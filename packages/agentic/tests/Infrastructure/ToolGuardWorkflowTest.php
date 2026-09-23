<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\ApprovalOutcome;
use Gplanchat\Agentic\Domain\Guard\ModeToolGuard;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\Agentic\Domain\Tool\Toolset;
use Gplanchat\Agentic\Application\Chat\AgentOutcome;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Gplanchat\Agentic\Domain\Tool\ToolInvocation;

#[CoversClass(ModeToolGuard::class)]
final class ToolGuardWorkflowTest extends TestCase
{
    private static function tools(): Toolset
    {
        return new Toolset(
            new ToolDefinition('weather', 'Weather', ToolEffect::Read),
            new ToolDefinition('save_note', 'Note', ToolEffect::Write),
            new ToolDefinition('send_email', 'Email', ToolEffect::External),
        );
    }

    public static function matrix(): \Generator
    {
        yield 'auto lets everything through' => [AgentMode::Auto, 'send_email', false];
        yield 'auto lets a write through' => [AgentMode::Auto, 'save_note', false];
        yield 'edition lets a write through' => [AgentMode::Edition, 'save_note', false];
        yield 'edition asks for an external effect' => [AgentMode::Edition, 'send_email', true];
        yield 'standard lets a read through' => [AgentMode::Standard, 'weather', false];
        yield 'standard asks for a write' => [AgentMode::Standard, 'save_note', true];
        yield 'standard asks for an external effect' => [AgentMode::Standard, 'send_email', true];
        // The cautious default: an unclassified tool is treated as external.
        yield 'an unknown tool is treated as external' => [AgentMode::Standard, 'rm_rf', true];
    }

    #[DataProvider('matrix')]
    public function testTheModeDecidesFromTheDeclaredEffect(AgentMode $mode, string $tool, bool $needsApproval): void
    {
        $decision = (new ModeToolGuard(self::tools()))->decide(new ToolInvocation('c1', $tool), $mode);

        self::assertSame($needsApproval, $decision->needsApproval());
        self::assertSame(!$needsApproval, $decision->isAllowed());
    }

    public function testADenyListWinsOverEveryMode(): void
    {
        $guard = new ModeToolGuard(self::tools(), ['send_email']);

        self::assertTrue($guard->decide(new ToolInvocation('c1', 'send_email'), AgentMode::Auto)->isDenied());
    }

    /**
     * With no answer before the deadline, the request falls — and it falls on the safe side: a
     * refusal. The virtual clock of the in-memory runner moves from deadline to deadline, so the
     * timer fires without really waiting.
     */
    public function testAnApprovalThatIsNeverAnsweredExpiresAsARefusal(): void
    {
        $toolCalls = 0;
        $round = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$round): array {
                if (0 === $round++) {
                    return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'send_email', 'arguments' => '{"to":"a@b.test"}'],
                    ]]], 'finish_reason' => 'tool_calls']]];
                }

                $last = end($payload['payload']['messages']);

                return ['choices' => [['message' => ['content' => $last['content']], 'finish_reason' => 'stop']]];
            },
            'ai_tool_call' => static function (array $payload) use (&$toolCalls): string {
                ++$toolCalls;

                return 'sent';
            },
        ]);

        $result = $environment->run(
            static fn ($workflowEnvironment): array => (new DurableAgentWorkflow($workflowEnvironment))->run(
                ['send_email' => ['description' => 'Send', 'effect' => 'external']],
                mode: 'standard',
                prompt: 'Send an email',
                maxTurns: 1,
                humanTimeoutSeconds: 5.0,
            ),
            'guard-timeout-1',
        );

        self::assertSame(0, $toolCalls, 'An expired approval triggered the tool anyway.');
        self::assertSame(ApprovalOutcome::Expired->message(), AgentOutcome::fromWire($result)->answer);
    }

    /**
     * A refusal is not an exception: it goes back as a tool result handed to the model, which
     * carries on. The activity, for its part, is never scheduled.
     */
    public function testADeniedToolNeverReachesItsActivityAndTheAgentKeepsGoing(): void
    {
        $toolCalls = 0;
        $round = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$round): array {
                if (0 === $round++) {
                    return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'send_email', 'arguments' => '{"to":"a@b.test"}'],
                    ]]], 'finish_reason' => 'tool_calls']]];
                }

                $last = end($payload['payload']['messages']);

                return ['choices' => [['message' => ['content' => 'Understood: '.$last['content']], 'finish_reason' => 'stop']]];
            },
            'ai_tool_call' => static function (array $payload) use (&$toolCalls): string {
                ++$toolCalls;

                return 'sent';
            },
        ]);

        $result = $environment->run(
            static fn ($workflowEnvironment): array => (new DurableAgentWorkflow($workflowEnvironment))->run(
                ['send_email' => ['description' => 'Send', 'effect' => 'external']],
                prompt: 'Send an email',
                maxTurns: 1,
                guard: new ModeToolGuard(self::tools(), ['send_email']),
            ),
            'guard-deny-1',
        );

        self::assertSame(0, $toolCalls, 'The activity of a refused tool was scheduled anyway.');
        self::assertStringContainsString('is forbidden by the agent policy', AgentOutcome::fromWire($result)->answer);
    }

    /**
     * The decision hooks travel in the payload: an allow lets the send go out in `standard` without
     * waiting for anyone, a deny stops it even in `auto`.
     */
    public function testToolRulesFromThePayloadDecideBeforeTheMode(): void
    {
        foreach (['allow' => [1, 'standard'], 'deny' => [0, 'auto']] as $decision => [$expectedCalls, $mode]) {
            $toolCalls = 0;
            $round = 0;
            $environment = WorkflowTestEnvironment::inMemory([
                'ai_model_invoke' => static function (array $payload) use (&$round): array {
                    if (0 === $round++) {
                        return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'send_email', 'arguments' => '{"to":"team@example.test"}'],
                        ]]], 'finish_reason' => 'tool_calls']]];
                    }

                    $last = end($payload['payload']['messages']);

                    return ['choices' => [['message' => ['content' => (string) $last['content']], 'finish_reason' => 'stop']]];
                },
                'ai_tool_call' => static function () use (&$toolCalls): string {
                    ++$toolCalls;

                    return 'sent';
                },
            ]);

            $environment->runWorkflowClass(DurableAgentWorkflow::class, [
                'tools' => ['send_email' => ['description' => 'Send', 'effect' => 'external']],
                'mode' => $mode,
                'prompt' => 'Send an email',
                'maxTurns' => 1,
                // If the rule did not play, the wait for an approval would expire straight away.
                'humanTimeoutSeconds' => 0.01,
                'toolRules' => [['tool' => 'send_email', 'when' => ['to' => '*@example.test'], 'decision' => $decision]],
            ], 'rules-'.$decision);

            self::assertSame($expectedCalls, $toolCalls, \sprintf('Rule "%s" in %s mode.', $decision, $mode));
        }
    }
}
