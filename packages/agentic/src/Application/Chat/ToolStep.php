<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Chat;

/**
 * A tool call actually executed, with its result once the activity has come back.
 */
final readonly class ToolStep implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $callId,
        public string $tool,
        public array $arguments,
        public ?string $result,
    ) {
    }

    public function withResult(?string $result): self
    {
        return new self($this->callId, $this->tool, $this->arguments, $result);
    }

    /**
     * `callId` goes all the way out to the wire: it is what makes it possible to match a displayed
     * call to the activity running it, hence to say "this one is still running". Matching on the
     * name and the arguments would work until the first agent that calls the same tool twice the
     * same way.
     *
     * @return array{callId: string, tool: string, arguments: array<string, mixed>, result: string|null}
     */
    public function jsonSerialize(): array
    {
        return ['callId' => $this->callId, 'tool' => $this->tool, 'arguments' => $this->arguments, 'result' => $this->result];
    }
}
