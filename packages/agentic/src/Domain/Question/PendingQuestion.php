<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Question;

/**
 * A question put to the human, waiting for their answer.
 *
 * It is the reverse of the guard: the guard decides whether a tool the model chose may go out,
 * this one is a tool whose only effect is to go and fetch a piece of information the model does
 * not have. Same primitive — a signal and a wait — two intentions.
 */
final readonly class PendingQuestion implements \JsonSerializable
{
    /**
     * @param list<QuestionOption> $options
     * @param float|null           $expiresAt instant (epoch) where the deadline will answer "nothing" in
     *                                        place of the human
     */
    public function __construct(
        public string $callId,
        public string $question,
        public string $header,
        public array $options,
        public bool $multiSelect = false,
        public ?float $expiresAt = null,
    ) {
    }

    /**
     * A boundary factory: the arguments come from the model, hence as arrays, and nothing
     * guarantees it honoured the schema. An option without a label is thrown away rather than
     * failing the turn.
     *
     * @param array<string, mixed> $arguments
     */
    public static function fromArguments(string $callId, array $arguments, ?float $expiresAt = null): self
    {
        $proposed = \is_array($arguments['options'] ?? null) ? $arguments['options'] : [];
        $options = [];
        foreach ($proposed as $option) {
            if (\is_array($option) && '' !== trim((string) ($option['label'] ?? ''))) {
                $options[] = QuestionOption::fromWire($option);
            }
        }

        return new self(
            $callId,
            (string) ($arguments['question'] ?? ''),
            (string) ($arguments['header'] ?? ''),
            $options,
            (bool) ($arguments['multiSelect'] ?? false),
            $expiresAt,
        );
    }

    public function expiringAt(?float $expiresAt): self
    {
        return new self($this->callId, $this->question, $this->header, $this->options, $this->multiSelect, $expiresAt);
    }

    /**
     * @return array{callId: string, question: string, header: string, options: list<QuestionOption>, multiSelect: bool, expiresAt: float|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'callId' => $this->callId,
            'question' => $this->question,
            'header' => $this->header,
            'options' => $this->options,
            'multiSelect' => $this->multiSelect,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
