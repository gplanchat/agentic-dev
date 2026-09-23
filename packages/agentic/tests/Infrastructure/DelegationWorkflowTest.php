<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\ToolVerdict;
use Gplanchat\Agentic\Domain\Team\DelegateTool;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * **Authority does not grow by delegation.**
 *
 * This is the invariant of the whole agent-team story. Without it, delegating is the escape hatch
 * of the guard: an agent in `standard` cannot send an email, but it would hand the task to a
 * sub-agent in `auto` that would send it. The guard would not be worked around through a flaw — it
 * would have become decorative.
 */
#[CoversClass(AgentMode::class)]
final class DelegationWorkflowTest extends TestCase
{
    public function testTheStrictestOfTheChainWins(): void
    {
        self::assertSame(AgentMode::Standard, AgentMode::strictest(AgentMode::Auto, AgentMode::Standard));
        self::assertSame(AgentMode::Edition, AgentMode::strictest(AgentMode::Auto, AgentMode::Edition));
        self::assertSame(AgentMode::Standard, AgentMode::strictest(AgentMode::Standard, AgentMode::Edition, AgentMode::Auto));
    }

    /**
     * A ceiling refuses what loosens it, and **only** that: a delegate that wants to be more
     * cautious than its ceiling is always allowed to.
     */
    public function testOnlyALooserModeIsRefusedByACeiling(): void
    {
        self::assertTrue(AgentMode::Auto->loosens(AgentMode::Standard));
        self::assertTrue(AgentMode::Edition->loosens(AgentMode::Standard));
        self::assertFalse(AgentMode::Standard->loosens(AgentMode::Auto));
        self::assertFalse(AgentMode::Standard->loosens(AgentMode::Standard));
    }

    /**
     * The mode requested at start-up bends to the ceiling — and we read it **through the guard**,
     * the only place where a mode means something: does the external tool go out, or does it wait
     * for an approval nobody will give?
     */
    public function testAnAgentStartedAboveItsCeilingIsClampedDown(): void
    {
        self::assertFalse(
            $this->externalToolRan(requested: 'auto', ceiling: 'standard'),
            'A delegated agent sent an email that its parent\'s guard refused it.',
        );
    }

    /**
     * The half that gets forgotten: the ceiling holds **afterwards too**. A sub-agent that accepted
     * `set_mode: auto` would have no ceiling at all.
     */
    public function testASetModeSignalCannotLoosenPastTheCeiling(): void
    {
        self::assertFalse(
            $this->externalToolRan(requested: 'standard', ceiling: 'standard', signalled: 'auto'),
            'A signal loosened the ceiling; the guard is decorative.',
        );
    }

    /**
     * The control: with no ceiling, exactly the same scenario goes through. Without it, the two
     * assertions above would pass even if the guard blocked everything for another reason.
     */
    public function testWithoutACeilingTheSameScenarioGoesThrough(): void
    {
        self::assertTrue(
            $this->externalToolRan(requested: 'auto', ceiling: 'auto'),
            'The control does not pass: the test proves nothing about the ceiling.',
        );
    }

    public function testTheDelegationToolIsOfferedAndHarmlessByItself(): void
    {
        $definition = DelegateTool::definition();

        self::assertSame('delegate', $definition->name);
        self::assertSame(
            ToolVerdict::Allow,
            AgentMode::Standard->verdictFor($definition->effect),
            'Delegating writes nowhere: it is what the delegate does that goes back through a guard.',
        );
    }

    /**
     * Runs an agent the model asks for an email send from — an `external` effect, the case only
     * `auto` lets through — and says whether the tool activity really ran.
     */
    private function externalToolRan(string $requested, string $ceiling, ?string $signalled = null): bool
    {
        $turns = 0;
        $ran = false;

        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function () use (&$turns): array {
                if (0 === $turns++) {
                    return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                        'id' => 'c1',
                        'type' => 'function',
                        'function' => ['name' => 'send_email', 'arguments' => '{"to":"x@example.test","body":"x"}'],
                    ]]], 'finish_reason' => 'tool_calls']]];
                }

                return ['choices' => [['message' => ['content' => 'done'], 'finish_reason' => 'stop']]];
            },
            'ai_tool_call' => static function () use (&$ran): string {
                $ran = true;

                return 'sent';
            },
        ]);

        $executionId = \sprintf('ceiling-%s-%s-%s', $requested, $ceiling, $signalled ?? 'nothing');
        $environment->getEventStore()->append(new ExecutionStarted($executionId, []));
        if (null !== $signalled) {
            $environment->getEventStore()->append(new WorkflowSignalReceived($executionId, 'set_mode', ['mode' => $signalled]));
        }

        $environment->runWorkflowClass(DurableAgentWorkflow::class, [
            'tools' => ['send_email' => ['description' => 'Sends an email.', 'effect' => 'external', 'parameters' => []]],
            'prompt' => 'Send the report',
            'maxTurns' => 1,
            'mode' => $requested,
            'modeCeiling' => $ceiling,
            // Nobody will approve: the wait must fall quickly so that the test holds in
            // milliseconds rather than in a quarter of an hour.
            'humanTimeoutSeconds' => 0.01,
        ], $executionId);

        return $ran;
    }
}
