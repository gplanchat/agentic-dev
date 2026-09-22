<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

use Gplanchat\Agentic\Domain\Tool\ToolInvocation;

/**
 * The equivalent of a Claude Code hook, but evaluated **in workflow code**.
 *
 * A consequence not to lose sight of: a guard is replayed on every resume, so it must be pure. A
 * guard that reads a database or a clock would make the replay diverge — if a decision needs an
 * outside fact, that fact must come through an activity, not through the guard.
 */
interface ToolGuardInterface
{
    public function decide(ToolInvocation $toolCall, AgentMode $mode): ToolDecision;
}
