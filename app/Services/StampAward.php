<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * What a StampAllocator::award() call actually did — which is not always what
 * was asked for: holiday stamps can land as regular ones, and stamps past the
 * member's card limit are refused.
 */
final class StampAward
{
    /**
     * @param  Collection<int, \App\Models\StampCard>  $cards  every card stamped, oldest first
     */
    public function __construct(
        public readonly int $holiday,
        public readonly int $regular,
        public readonly int $converted,
        public readonly int $refused,
        public readonly Collection $cards,
        public readonly ?int $cardLimit,
    ) {}

    public function applied(): int
    {
        return $this->holiday + $this->regular;
    }

    /** One sentence for the toast / flash message, saying exactly what happened. */
    public function message(string $customerName): string
    {
        $applied = $this->applied();
        $requested = $applied + $this->refused;
        $noun = fn (int $n) => $n === 1 ? 'stamp' : 'stamps';

        $sentence = $this->refused > 0
            ? "{$applied} of {$requested} {$noun($requested)} added for {$customerName}"
            : "{$applied} {$noun($applied)} added for {$customerName}";

        if ($this->holiday > 0 && $this->regular > 0) {
            $sentence .= " ({$this->holiday} Holiday, {$this->regular} Regular)";
        } elseif ($this->holiday > 0) {
            $sentence .= ' (Holiday)';
        }

        $sentence .= '.';

        if ($this->converted > 0) {
            $sentence .= " {$this->converted} Holiday {$noun($this->converted)} became Regular — "
                . 'every Holiday slot on the cards this member can hold is already filled.';
        }

        if ($this->refused > 0) {
            $sentence .= $this->cardLimit
                ? " The rest did not fit: this member has reached the limit of {$this->cardLimit} "
                    . ($this->cardLimit === 1 ? 'card' : 'cards') . ' for this program.'
                : ' The rest did not fit on this program\'s cards.';
        }

        return $sentence;
    }

    /** The JSON the scan endpoints answer with, beside the card itself. */
    public function toArray(): array
    {
        return [
            'applied' => $this->applied(),
            'holiday' => $this->holiday,
            'regular' => $this->regular,
            'converted' => $this->converted,
            'refused' => $this->refused,
            'card_ids' => $this->cards->pluck('id')->values(),
        ];
    }
}
