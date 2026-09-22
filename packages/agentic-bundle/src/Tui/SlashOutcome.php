<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

/**
 * What a chat command hands back to the screen.
 */
final readonly class SlashOutcome
{
    /**
     * @param list<array{value: string, label: string, description?: string}> $choices something to choose from, when the command waits to be told which
     */
    public function __construct(
        public string $notice,
        public bool $error = false,
        /** The conversation to show from now on, when the command has opened another one. */
        public ?string $conversation = null,
        public array $choices = [],
        /** The command to run again with the chosen value, for example `/rewind`. */
        public ?string $choose = null,
        /** What to put back into the input — the message that was just undone. */
        public ?string $prefill = null,
    ) {
    }
}
