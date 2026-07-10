<?php

namespace App\Http\Services;

use App\Models\InboundEmail;
use App\Models\Vendor;
use App\Models\VendorIntakeEmail;
use Illuminate\Support\Facades\Log;
use Webklex\IMAP\Facades\Client;

/**
 * Polls the intake mailbox and turns vendor attachments into intake documents.
 * Sender matching: exact registered address -> registered domain -> unmatched
 * (routed to the exception queue for manual classification).
 */
class EmailIntakeService
{
    private const DOCUMENT_EXTENSIONS = ['pdf', 'doc', 'docx'];

    public function __construct(
        private DocumentIntakeService $intake,
        private DocumentExceptionService $exceptions,
    ) {
    }

    public function fetchAndProcess(): array
    {
        set_time_limit(180);

        if (! config('imap.accounts.default.host') || ! config('imap.accounts.default.username')) {
            return ['status' => 'skipped', 'message' => 'IMAP is not configured (IMAP_HOST / IMAP_USERNAME).'];
        }

        $client = Client::account('default');
        $client->connect();

        $inbox = collect($client->getFolders())->first(fn ($f) => strtolower($f->name) === 'inbox');
        if (! $inbox) {
            $client->disconnect();

            return ['status' => 'error', 'message' => 'Inbox folder not found.'];
        }

        $processed = 0;
        $errors = [];

        foreach ($inbox->messages()->unseen()->get() as $message) {
            try {
                if ($this->processMessage($message)) {
                    $processed++;
                }
            } catch (\Throwable $e) {
                Log::error('EmailIntakeService: message failed: '.$e->getMessage());
                $errors[] = $e->getMessage();
            }
        }

        $client->disconnect();

        return [
            'status' => empty($errors) ? 'success' : 'warning',
            'message' => "Processed {$processed} email(s).".(empty($errors) ? '' : ' Errors: '.implode(' | ', $errors)),
            'count' => $processed,
            'errors' => $errors,
        ];
    }

    protected function processMessage($message): bool
    {
        $messageId = trim((string) $message->getMessageId(), '<> ') ?: 'no-id-'.sha1((string) $message->getSubject().now());
        $fromEmail = strtolower(trim($message->getFrom()[0]->mail ?? ''));

        if (InboundEmail::where('message_id', $messageId)->exists()) {
            $message->setFlag('Seen');

            return false;
        }

        $attachments = $message->getAttachments();
        $documentAttachments = $attachments->filter(function ($attachment) {
            $ext = strtolower(pathinfo((string) $attachment->getName(), PATHINFO_EXTENSION));

            return in_array($ext, self::DOCUMENT_EXTENSIONS, true);
        });

        [$vendor, $matchMethod] = $this->matchVendor($fromEmail);

        $email = InboundEmail::create([
            'message_id' => $messageId,
            'from_email' => $fromEmail,
            'from_name' => $message->getFrom()[0]->personal ?? null,
            'subject' => mb_substr((string) $message->getSubject(), 0, 255),
            'received_at' => $message->getDate()?->toDate() ?? now(),
            'matched_vendor_id' => $vendor?->id,
            'match_method' => $matchMethod,
            'status' => $documentAttachments->isEmpty() ? 'discarded' : ($vendor ? 'processed' : 'unmatched'),
            'meta' => [
                'attachments' => $attachments->map(fn ($a) => (string) $a->getName())->values()->all(),
                'skipped' => $attachments->count() - $documentAttachments->count(),
            ],
        ]);

        foreach ($documentAttachments as $attachment) {
            $this->intake->createFromEmail(
                $email,
                $attachment->getContent(),
                (string) $attachment->getName() ?: 'attachment.pdf',
                $vendor,
            );
        }

        if ($documentAttachments->isNotEmpty() && ! $vendor) {
            PortalNotifier::notifyUsersWithPermission(
                'document-exceptions.view',
                null,
                'document_exception',
                'Unmatched vendor email received',
                "Documents from {$fromEmail} need manual vendor assignment.",
                route('document-exceptions.index', [], false),
            );
        }

        $message->setFlag('Seen');

        return $documentAttachments->isNotEmpty();
    }

    /** @return array{0: ?Vendor, 1: string} */
    protected function matchVendor(string $fromEmail): array
    {
        if ($fromEmail === '') {
            return [null, 'none'];
        }

        // 1. Exact registered intake address or the vendor's login email
        $intakeEmail = VendorIntakeEmail::where('type', 'email')->where('value', $fromEmail)->first();
        $vendor = $intakeEmail?->vendor ?? Vendor::where('email', $fromEmail)->first();
        if ($vendor) {
            return [$vendor, 'exact'];
        }

        // 2. Registered domain
        $domain = strtolower(substr(strrchr($fromEmail, '@') ?: '', 1));
        if ($domain !== '') {
            $intakeDomain = VendorIntakeEmail::where('type', 'domain')->where('value', $domain)->first();
            if ($intakeDomain?->vendor) {
                return [$intakeDomain->vendor, 'domain'];
            }
        }

        return [null, 'none'];
    }
}
