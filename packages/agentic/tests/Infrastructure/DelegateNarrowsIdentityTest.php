<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Delegation on the identity axis: the sub-agent keeps its caller's identity, holding only what its
 * profile still allows of it.
 *
 * The property tested is the same one {@see \Gplanchat\Agentic\Domain\Guard\AgentMode::strictest()}
 * gives the mode — naming a sub-agent grants nothing — and it is observed the only way that proves
 * anything: not by reading the child's payload, but by watching its guard act on it.
 *
 * It is also what guards the positional call in
 * {@see \Gplanchat\Agentic\Infrastructure\SymfonyAi\DurableToolExecutor::delegate()}: the child's
 * arguments are matched **by position**, so a parameter added to the middle of `run()` would send
 * `owner` into the wrong slot and every child would silently start claiming nothing. The first test
 * below fails when that happens, because it is the one that expects a role to have *survived*.
 */
#[CoversClass(DurableAgentWorkflow::class)]
final class DelegateNarrowsIdentityTest extends TestCase
{
    private const TOOLS = [
        'send_email' => ['description' => 'Mail', 'effect' => 'external'],
    ];

    /** Refused to everyone, except to whoever still holds `support`. */
    private const RULES = [
        ['tool' => 'send_email', 'decision' => 'deny', 'unless_roles' => ['support'], 'reason' => 'Only support mails.'],
    ];

    private const ALICE = ['id' => 'alice', 'roles' => ['finance', 'support']];

    /**
     * The one that catches a shifted slot: the child must still hold `support` after narrowing, so
     * it must be allowed to send. A child that received no owner at all would be refused here.
     */
    public function testTheSubAgentKeepsTheRoleItsProfileStillAllows(): void
    {
        self::assertSame(
            ['send_email'],
            $this->executedByTheSubAgent(['support']),
            'The sub-agent kept `support` and its guard let the mail through.',
        );
    }

    public function testAProfileThatAllowsNothingClaimsNothing(): void
    {
        self::assertSame([], $this->executedByTheSubAgent([]), 'A sub-agent with no role must be refused.');
    }

    /**
     * The property the intersection exists for: a profile naming a role its caller does not hold
     * grants nothing. `admin` is not alice's, so the child ends up claiming nothing.
     */
    public function testAProfileCannotGrantARoleTheCallerDoesNotHold(): void
    {
        self::assertSame([], $this->executedByTheSubAgent(['admin']), 'Delegation widened an identity.');
    }

    /**
     * The caller itself is not narrowed: alice holds `support`, so the same rule lets her through.
     * Without this, the three above would pass just as well on a guard that refuses everyone.
     */
    public function testTheCallerItselfIsNotNarrowed(): void
    {
        $executed = [];
        $round = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            // A closure and not an arrow function: an arrow function captures `$round` by value,
            // so the counter never advances and the model asks for the same tool for ever.
            'ai_model_invoke' => static function () use (&$round): array {
                return 0 === $round++
                    ? self::callOf('call_p', 'send_email', '{"to":"team@example.test"}')
                    : self::says('done');
            },
            'ai_tool_call' => static function (array $payload) use (&$executed): string {
                $executed[] = self::nameOf($payload);

                return 'sent';
            },
        ]);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, [
            'tools' => self::TOOLS,
            'toolRules' => self::RULES,
            'owner' => self::ALICE,
            'mode' => 'auto',
            'prompt' => 'Mail the team',
            'maxTurns' => 1,
        ], 'narrow-caller');

        self::assertSame(['send_email'], $executed);
    }

    /**
     * @param list<string> $profileRoles
     *
     * @return list<string> the tools the SUB-AGENT actually ran
     */
    private function executedByTheSubAgent(array $profileRoles): array
    {
        $executed = [];
        $round = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function () use (&$round): array {
                // 0: the parent delegates. 1: the sub-agent tries to mail. Then both answer.
                return match ($round++) {
                    0 => self::callOf('call_1', 'delegate', '{"mission":"Mail the team","agent":"courier"}'),
                    1 => self::callOf('call_2', 'send_email', '{"to":"team@example.test"}'),
                    default => self::says('done'),
                };
            },
            'ai_tool_call' => static function (array $payload) use (&$executed): string {
                $executed[] = self::nameOf($payload);

                return 'sent';
            },
        ]);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, [
            'tools' => self::TOOLS,
            'toolRules' => self::RULES,
            'agents' => ['courier' => [
                'description' => 'Sends mail',
                'prompt' => 'You send mail.',
                'ceiling' => 'auto',
                'tools' => ['send_email'],
                'roles' => $profileRoles,
            ]],
            'owner' => self::ALICE,
            // `auto` throughout: nothing here asks for approval, so what refuses can only be the
            // rule — which is the point.
            'mode' => 'auto',
            'prompt' => 'Delegate the mail',
            'maxTurns' => 1,
        ], 'narrow-'.implode('-', $profileRoles ?: ['none']));

        return $executed;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function nameOf(array $payload): string
    {
        while (!\array_key_exists('name', $payload) && \is_array($payload['payload'] ?? null)) {
            $payload = $payload['payload'];
        }

        return (string) ($payload['name'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private static function callOf(string $id, string $tool, string $arguments): array
    {
        return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $tool, 'arguments' => $arguments],
        ]]], 'finish_reason' => 'tool_calls']]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function says(string $text): array
    {
        return ['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']]];
    }
}
