<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

/**
 * What the banana says while the agent works — the equivalent of Claude Code's "Pondering…".
 * Purely decorative: nothing here goes into the journal, so randomness is allowed.
 */
final class BananaWords
{
    public const PHRASES = [
        'Peeling the question',
        'Ripening in the sun',
        'Consulting the banana tree',
        'Counting the bunches',
        'Slipping on a banana peel',
        'Preparing a banana split',
        'Blending a smoothie',
        'Dancing the Peanut Butter Jelly',
        'Negotiating with the monkeys',
        'Looking for the perfect curve',
        'Juggling three bananas',
        'Checking the potassium level',
        'Revving up the bunch',
        'Peeling the assumptions',
        'Spreading the peanut butter',
        'Flambeing the banana',
        'Browning a little at the edges',
        'Climbing the coconut tree (wrong aisle)',
    ];

    /** Braille: one cell wide everywhere, unlike emojis. */
    private const SPINNER = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /** A phrase holds for this many beats before giving way to the next one. */
    private const BEATS_PER_PHRASE = 8;

    private int $first;

    public function __construct(?int $seed = null)
    {
        $this->first = $seed ?? random_int(0, \count(self::PHRASES) - 1);
    }

    /**
     * A new run starts: the banana does not repeat its last joke right away.
     */
    public function shuffle(): void
    {
        $this->first = ($this->first + random_int(1, \count(self::PHRASES) - 1)) % \count(self::PHRASES);
    }

    public function line(int $beat, float $elapsedSeconds): string
    {
        $phrase = self::PHRASES[($this->first + intdiv($beat, self::BEATS_PER_PHRASE)) % \count(self::PHRASES)];

        return \sprintf(
            "\e[38;2;255;214;64m%s %s…\e[0m \e[2m(%d s)\e[0m",
            self::SPINNER[$beat % \count(self::SPINNER)],
            $phrase,
            (int) $elapsedSeconds,
        );
    }
}
