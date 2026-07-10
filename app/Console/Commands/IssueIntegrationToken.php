<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IssueIntegrationToken extends Command
{
    protected $signature = 'portal:issue-integration-token {system=ghelpdesk : Consuming system name}';

    protected $description = 'Create (or reuse) a service user and issue a Sanctum token for app-to-app calls into linkportal';

    public function handle(): int
    {
        $system = strtolower($this->argument('system'));
        $email = "integration+{$system}@linkportal.local";

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Integration: '.ucfirst($system),
                'password' => Str::random(64), // never used for login
                'is_active' => true,
            ],
        );

        // One live token per system: revoke old ones on re-issue
        $user->tokens()->where('name', "{$system}-integration")->delete();
        $token = $user->createToken("{$system}-integration");

        $this->info("Service user: {$email} (id {$user->id})");
        $this->newLine();
        $this->line('Plaintext token (shown once — put it in the other app\'s .env):');
        $this->warn($token->plainTextToken);

        return self::SUCCESS;
    }
}
