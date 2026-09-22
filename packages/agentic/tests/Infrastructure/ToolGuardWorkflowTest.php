<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\ApprovalOutcome;
use Gplanchat\Agentic\Domain\Guard\ModeToolGuard;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\Agentic\Domain\Tool\Toolset;
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
            new ToolDefinition('weather', 'Météo', ToolEffect::Read),
            new ToolDefinition('save_note', 'Note', ToolEffect::Write),
            new ToolDefinition('send_email', 'Courriel', ToolEffect::External),
        );
    }

    public static function matrix(): \Generator
    {
        yield 'auto laisse tout passer' => [AgentMode::Auto, 'send_email', false];
        yield 'auto laisse passer une écriture' => [AgentMode::Auto, 'save_note', false];
        yield 'edition laisse passer une écriture' => [AgentMode::Edition, 'save_note', false];
        yield 'edition demande pour un effet externe' => [AgentMode::Edition, 'send_email', true];
        yield 'standard laisse passer une lecture' => [AgentMode::Standard, 'weather', false];
        yield 'standard demande pour une écriture' => [AgentMode::Standard, 'save_note', true];
        yield 'standard demande pour un effet externe' => [AgentMode::Standard, 'send_email', true];
        // Le défaut prudent : un outil non classé est traité comme externe.
        yield 'un outil inconnu est traité comme externe' => [AgentMode::Standard, 'rm_rf', true];
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
     * Sans réponse avant l'échéance, la demande tombe — et elle tombe du côté sûr : refus.
     * L'horloge virtuelle du runner in-memory avance d'échéance en échéance, donc le minuteur tire
     * sans attendre réellement.
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

                return 'envoyé';
            },
        ]);

        $result = $environment->run(
            static fn ($workflowEnvironment): string => (new DurableAgentWorkflow($workflowEnvironment))->run(
                ['send_email' => ['description' => 'Envoi', 'effect' => 'external']],
                mode: 'standard',
                prompt: 'Envoie un mail',
                maxTurns: 1,
                humanTimeoutSeconds: 5.0,
            ),
            'guard-timeout-1',
        );

        self::assertSame(0, $toolCalls, 'Une validation expirée a quand même déclenché l\'outil.');
        self::assertSame(ApprovalOutcome::Expired->message(), $result);
    }

    /**
     * Un refus n'est pas une exception : il redevient un résultat d'outil rendu au modèle, qui
     * continue. L'activité, elle, n'est jamais planifiée.
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

                return ['choices' => [['message' => ['content' => 'Compris : '.$last['content']], 'finish_reason' => 'stop']]];
            },
            'ai_tool_call' => static function (array $payload) use (&$toolCalls): string {
                ++$toolCalls;

                return 'envoyé';
            },
        ]);

        $result = $environment->run(
            static fn ($workflowEnvironment): string => (new DurableAgentWorkflow($workflowEnvironment))->run(
                ['send_email' => ['description' => 'Envoi', 'effect' => 'external']],
                prompt: 'Envoie un mail',
                maxTurns: 1,
                guard: new ModeToolGuard(self::tools(), ['send_email']),
            ),
            'guard-deny-1',
        );

        self::assertSame(0, $toolCalls, 'L\'activité d\'un outil refusé a quand même été planifiée.');
        self::assertStringContainsString('interdit par la politique', $result);
    }

    /**
     * Les hooks de décision voyagent dans la charge : un accord laisse partir l'envoi en `standard`
     * sans attendre personne, un refus l'arrête même en `auto`.
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
                            'function' => ['name' => 'send_email', 'arguments' => '{"to":"equipe@example.test"}'],
                        ]]], 'finish_reason' => 'tool_calls']]];
                    }

                    $last = end($payload['payload']['messages']);

                    return ['choices' => [['message' => ['content' => (string) $last['content']], 'finish_reason' => 'stop']]];
                },
                'ai_tool_call' => static function () use (&$toolCalls): string {
                    ++$toolCalls;

                    return 'envoyé';
                },
            ]);

            $environment->runWorkflowClass(DurableAgentWorkflow::class, [
                'tools' => ['send_email' => ['description' => 'Envoi', 'effect' => 'external']],
                'mode' => $mode,
                'prompt' => 'Envoie un mail',
                'maxTurns' => 1,
                // Si la règle ne jouait pas, l'attente d'une validation expirerait aussitôt.
                'humanTimeoutSeconds' => 0.01,
                'toolRules' => [['tool' => 'send_email', 'when' => ['to' => '*@example.test'], 'decision' => $decision]],
            ], 'rules-'.$decision);

            self::assertSame($expectedCalls, $toolCalls, \sprintf('Règle « %s » en mode %s.', $decision, $mode));
        }
    }
}
