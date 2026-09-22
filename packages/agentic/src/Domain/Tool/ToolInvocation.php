<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Tool;

/**
 * A tool call requested by the model, stated in the vocabulary of the domain.
 *
 * The domain does not speak the provider's type (`Symfony\AI\Platform\Result\ToolCall`, 0.x and
 * with no promise of compatibility): the adapter translates at the boundary, and the guard only
 * ever sees this.
 */
final readonly class ToolInvocation
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $callId,
        public string $name,
        public array $arguments = [],
    ) {
        if ('' === trim($callId)) {
            throw new \InvalidArgumentException('A tool call must carry an identifier.');
        }
    }
}
