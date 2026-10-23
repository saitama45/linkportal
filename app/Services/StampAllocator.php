<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\StampCard;
use App\Models\StampEntry;
use App\Models\StampProgram;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Decides which of a member's cards each earned stamp lands on, and writes it.
 *
 * A program can reserve some of a card's slots for Holiday stamps (the paper
 * holiday card: 4 "Holiday Drink" circles + 16 "Any Drink") and can cap how
 * many cards one member may ever hold. Together those make placement a real
 * decision rather than "the active card":
 *
 *  - A Holiday stamp takes the oldest open card with a free holiday slot, even
 *    one that still has empty regular slots — so a member who only ever buys
 *    holiday drinks fills card 1's holiday slots, then card 2's, and so on.
 *    With none free it opens the next card if the limit allows; at the limit
 *    it is recorded as a Regular stamp instead (a holiday drink is still "any
 *    drink"), and the result says how many were converted.
 *  - A Regular stamp takes the oldest open card with a free regular slot, else
 *    opens the next card if the limit allows, else it is refused.
 *
 * Each stamp is placed on its own, so a purchase worth several flows onto the
 * next card instead of being cut off where the first one fills. A full card
 * still waiting to be redeemed is never stamped — it is not `active`.
 *
 * A copy of the LINK HUB's class of the same name
 * (ghelpdesk/app/Services/StampAllocator.php), which owns these tables. Every
 * earning path at the cashier goes through it: scan, Add Stamps and Record
 * Purchase. Both apps write the same tables, so keep the two identical — a
 * rule changed in one must be changed in the other.
 */
final class StampAllocator
{
    public const REGULAR = 'regular';

    public const HOLIDAY = 'holiday';

    public const TYPES = [self::REGULAR, self::HOLIDAY];

    /**
     * @param  array  $newCard  attributes for a card this award has to open (store, creator)
     * @param  array  $touch  attributes written on every card stamped (who touched it last)
     * @param  array  $entry  attributes copied onto every entry written (store, source, note, actor, receipt)
     */
    public function award(
        int $customerId,
        StampProgram $program,
        string $type,
        int $quantity,
        $purchaseAmount,
        array $newCard,
        array $touch,
        array $entry,
    ): StampAward {
        // A program without holiday slots has nothing to put a Holiday stamp
        // in; that is a Regular stamp, not a conversion worth reporting.
        $type = $type === self::HOLIDAY && $program->holidaySlots() > 0 ? self::HOLIDAY : self::REGULAR;

        $award = DB::transaction(function () use ($customerId, $program, $type, $quantity, $purchaseAmount, $newCard, $touch, $entry) {
            // Serialises awards per member, so two tills scanning the same
            // member at once cannot both open "card 3 of 3".
            Customer::query()->whereKey($customerId)->lockForUpdate()->first();

            $cards = StampCard::where('customer_id', $customerId)
                ->where('stamp_program_id', $program->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $tally = [];
            $converted = 0;
            $refused = 0;

            for ($i = 0; $i < $quantity; $i++) {
                $card = null;
                $slot = self::REGULAR;

                if ($type === self::HOLIDAY) {
                    $card = $this->openCardWith($cards, fn (StampCard $c) => $c->holidayFree($program) > 0)
                        ?? $this->newCard($cards, $customerId, $program, $newCard);
                    $slot = self::HOLIDAY;
                }

                if (! $card) {
                    $slot = self::REGULAR;
                    $card = $this->openCardWith($cards, fn (StampCard $c) => $c->regularFree($program) > 0)
                        ?? ($program->regularSlots() > 0 ? $this->newCard($cards, $customerId, $program, $newCard) : null);

                    if (! $card) {
                        $refused = $quantity - $i;
                        break;
                    }

                    if ($type === self::HOLIDAY) {
                        $converted++;
                    }
                }

                if ($slot === self::HOLIDAY) {
                    $card->holiday_count = (int) $card->holiday_count + 1;
                }
                $card->stamps_count = (int) $card->stamps_count + 1;

                if ($card->stamps_count >= (int) $program->stamps_required) {
                    $card->status = 'completed';
                    $card->completed_at = now();
                }

                $tally[$card->id] ??= ['card' => $card, self::HOLIDAY => 0, self::REGULAR => 0];
                $tally[$card->id][$slot]++;
            }

            $amountLeft = $purchaseAmount;
            foreach ($tally as $row) {
                $row['card']->fill($touch)->save();

                foreach ([self::HOLIDAY, self::REGULAR] as $slot) {
                    if ($row[$slot] < 1) {
                        continue;
                    }

                    StampEntry::create($entry + [
                        'stamp_card_id' => $row['card']->id,
                        'quantity' => $row[$slot],
                        'stamp_type' => $slot,
                        // One purchase, however many cards it spread over:
                        // the amount is recorded once so sales totals (sums
                        // of this column) are not counted twice.
                        'purchase_amount' => $amountLeft,
                    ]);
                    $amountLeft = null;
                }
            }

            $placed = collect($tally);

            return new StampAward(
                holiday: $placed->sum(self::HOLIDAY),
                regular: $placed->sum(self::REGULAR),
                converted: $converted,
                refused: $refused,
                cards: $placed->pluck('card')->values(),
                cardLimit: $program->cardLimit(),
            );
        });

        if ($award->applied() < 1) {
            throw ValidationException::withMessages([
                'quantity' => $program->cardLimit()
                    ? "This member already holds the limit of {$program->cardLimit()} "
                        . ($program->cardLimit() === 1 ? 'card' : 'cards')
                        . ' for this program, and every one of them is full.'
                    : 'This program\'s cards have no slot this stamp type can fill.',
            ]);
        }

        return $award;
    }

    /** Whether one more card may be opened for this member under the program's limit. */
    public static function canOpenCard(StampProgram $program, int $cardsHeld): bool
    {
        return $program->cardLimit() === null || $cardsHeld < $program->cardLimit();
    }

    /** The oldest still-collecting card that passes [$hasRoom]. */
    private function openCardWith(Collection $cards, \Closure $hasRoom): ?StampCard
    {
        return $cards->first(fn (StampCard $c) => $c->status === 'active' && $hasRoom($c));
    }

    /** Opens the next card, or returns null at the member's card limit. */
    private function newCard(Collection $cards, int $customerId, StampProgram $program, array $attributes): ?StampCard
    {
        if (! self::canOpenCard($program, $cards->count())) {
            return null;
        }

        $card = StampCard::create($attributes + [
            'customer_id' => $customerId,
            'stamp_program_id' => $program->id,
            'stamps_count' => 0,
            'holiday_count' => 0,
            'status' => 'active',
        ]);

        $cards->push($card);

        return $card;
    }
}
