<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Activity;

use Gplanchat\Agentic\Infrastructure\Durable\Activity\AgentToolActivityInterface;
use Gplanchat\AgenticBundle\Tool\AgentTools;

/**
 * Routes a tool call to its implementation. Every call is an activity: journalled, retried
 * according to its `ActivityOptions`, and never re-executed on replay.
 */
final readonly class AgentToolActivityHandler implements AgentToolActivityInterface
{
    public function __construct(private AgentTools $tools)
    {
    }

    public function callTool(string $callId, string $name, array $arguments, ?string $workspace = null): string
    {
        return $this->tools->call($name, $arguments, $workspace);
    }
}
