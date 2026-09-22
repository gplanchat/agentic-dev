<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

use Gplanchat\Agentic\Domain\Tool\ToolInvocation;

/**
 * A tool call held back by the guard, waiting for a human decision.
 */
final readonly class PendingApproval implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $arguments
     * @param float|null           $expiresAt instant (epoch, seconds) where the deadline will decide in
     *                                        place of the human; `null` = no deadline
     */
    public function __construct(
        public string $callId,
        public string $tool,
        public array $arguments,
        public string $reason,
        public ?float $expiresAt = null,
    ) {
    }

    public function expiringAt(?float $expiresAt): self
    {
        return new self($this->callId, $this->tool, $this->arguments, $this->reason, $expiresAt);
    }

    public static function of(ToolInvocation $toolCall, string $reason): self
    {
        return new self($toolCall->callId, $toolCall->name, $toolCall->arguments, $reason);
    }

    /**
     * @return array{callId: string, tool: string, arguments: array<string, mixed>, reason: string, expiresAt: float|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'callId' => $this->callId,
            'tool' => $this->tool,
            'arguments' => $this->arguments,
            'reason' => $this->reason,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
