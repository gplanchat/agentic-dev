<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Chat;

/**
 * The tool call as it appears in the thread, freed from the provider's "function" envelope and
 * from its arguments encoded as a JSON string.
 */
final readonly class ToolCallRef implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $callId,
        public string $tool,
        public array $arguments,
    ) {
    }

    /**
     * @param array<string, mixed> $wire
     */
    public static function fromWire(array $wire): self
    {
        return new self(
            (string) ($wire['id'] ?? ''),
            (string) ($wire['function']['name'] ?? '?'),
            json_decode((string) ($wire['function']['arguments'] ?? '{}'), true) ?: [],
        );
    }

    /**
     * @return array{callId: string, tool: string, arguments: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return ['callId' => $this->callId, 'tool' => $this->tool, 'arguments' => $this->arguments];
    }
}
