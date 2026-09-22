<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Question;

/**
 * A proposed answer. The label is what the human clicks and what the model reads back — it
 * therefore acts as an identifier, and it must stand on its own.
 */
final readonly class QuestionOption implements \JsonSerializable
{
    public function __construct(
        public string $label,
        public string $description = '',
    ) {
        if ('' === trim($label)) {
            throw new \InvalidArgumentException('An option must carry a label.');
        }
    }

    /**
     * @param array<string, mixed> $wire
     */
    public static function fromWire(array $wire): self
    {
        return new self((string) ($wire['label'] ?? ''), (string) ($wire['description'] ?? ''));
    }

    /**
     * @return array{label: string, description: string}
     */
    public function jsonSerialize(): array
    {
        return ['label' => $this->label, 'description' => $this->description];
    }
}
