<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\Durable\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * An agent tool is a side effect: it is an activity, not workflow code.
 *
 * ponytail: a single generic contract for the prototype. A tool that needs its own retry policy or
 * a compensation deserves its own activity contract.
 */
interface AgentToolActivityInterface
{
    /**
     * @param array<string, mixed> $arguments
     * @param string|null          $workspace the conversation's working directory, from its start
     *                                        payload — never from the model; `null` = the project
     * @param array<string, mixed> $owner     on whose behalf the conversation runs; `[]` = nobody
     */
    #[AsActivityMethod('ai_tool_call')]
    public function callTool(string $callId, string $name, array $arguments, ?string $workspace = null, array $owner = []): string;
}
