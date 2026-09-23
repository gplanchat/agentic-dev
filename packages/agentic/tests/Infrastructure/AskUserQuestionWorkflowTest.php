<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Domain\Guard\ModeToolGuard;
use Gplanchat\Agentic\Domain\Question\AskUserQuestion;
use Gplanchat\Agentic\Application\Chat\AgentOutcome;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The tool whose execution is a suspension: its result is not computed, it is awaited.
 *
 * It is the reverse of the guard — the guard decides whether a tool the model chose may go out,
 * this one goes and fetches what the model does not know. Same primitive, a signal and a wait.
 */
#[CoversClass(AskUserQuestion::class)]
final class AskUserQuestionWorkflowTest extends TestCase
{
    private const CALL = 'q1';

    public function testTheQuestionToolIsAlwaysOfferedEvenWithoutAnyOtherTool(): void
    {
        $offered = [];
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$offered): array {
                foreach ($payload['options']['tools'] ?? [] as $tool) {
                    $offered[] = $tool['function']['name'] ?? '?';
                }

                return self::text('Hello.');
            },
        ]);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'question-0');

        self::assertContains(AskUserQuestion::TOOL, $offered);
    }

    public function testTheAnswerComesBackToTheModelAsTheToolResult(): void
    {
        $environment = WorkflowTestEnvironment::inMemory($this->scriptedModel());

        // The answer is already in the journal: the workflow applies it on reaching its condition.
        // It really is a signal — the same one the page would send, in the same place.
        $this->answerUpFront($environment, 'question-1', ['Batch']);

        self::assertSame('Understood: Batch', AgentOutcome::fromWire($environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'question-1'))->answer);
    }

    public function testSeveralAnswersTravelTogetherWhenTheQuestionAllowsIt(): void
    {
        $environment = WorkflowTestEnvironment::inMemory($this->scriptedModel());
        $this->answerUpFront($environment, 'question-2', ['Batch', 'In the background']);

        self::assertSame('Understood: Batch; In the background', AgentOutcome::fromWire($environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'question-2'))->answer);
    }

    /**
     * With no answer, the agent does not stay stuck: the deadline hands it back a sentence saying
     * so, and it carries on. The runner's virtual clock moves from deadline to deadline.
     */
    public function testAQuestionNobodyAnswersExpiresAndTheAgentIsToldSo(): void
    {
        $environment = WorkflowTestEnvironment::inMemory($this->scriptedModel());

        $answer = $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(5.0), 'question-3');
        $answer = AgentOutcome::fromWire($answer)->answer;

        self::assertStringContainsString('No answer', $answer);
    }

    /**
     * The guard stays in front, not on the side: it decides on **every** call, including this one.
     *
     * In `standard` mode, where every write needs an approval, asking a question goes through
     * without asking anything — it is classified `read`. Had the guard held it back, this test
     * would stay suspended for want of a `tool_decision` signal, and that is exactly what it checks.
     */
    public function testAskingIsNotSomethingOneHasToApproveFirst(): void
    {
        $environment = WorkflowTestEnvironment::inMemory($this->scriptedModel());
        $this->answerUpFront($environment, 'question-5', ['Batch']);

        $input = ['mode' => 'standard'] + $this->input();

        self::assertSame(
            'Understood: Batch',
            AgentOutcome::fromWire($environment->runWorkflowClass(DurableAgentWorkflow::class, $input, 'question-5'))->answer,
        );
    }

    /**
     * And the reverse holds too: a policy that forbids asking questions forbids them for good. That
     * is the proof that the guard really is upstream — otherwise the desk would suspend the
     * execution before it had its say.
     */
    public function testAPolicyCanForbidAskingAltogether(): void
    {
        $environment = WorkflowTestEnvironment::inMemory($this->scriptedModel());

        // No signal is dropped: if the question reached the desk, the execution would stay suspended
        // instead of returning an answer.
        $input = ['guard' => new ModeToolGuard(denied: [AskUserQuestion::TOOL])] + $this->input();
        $answer = $environment->runWorkflowClass(DurableAgentWorkflow::class, $input, 'question-6');
        $answer = AgentOutcome::fromWire($answer)->answer;

        self::assertStringContainsString('is forbidden by the agent policy', $answer);
    }

    /**
     * No activity is scheduled for a question: there is nothing to run.
     */
    public function testAQuestionNeverReachesAnActivity(): void
    {
        $toolCalls = 0;
        $handlers = $this->scriptedModel();
        $handlers['ai_tool_call'] = static function (array $payload) use (&$toolCalls): string {
            ++$toolCalls;

            return 'ran';
        };

        $environment = WorkflowTestEnvironment::inMemory($handlers);
        $this->answerUpFront($environment, 'question-4', ['Batch']);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'question-4');

        self::assertSame(0, $toolCalls);
    }

    /**
     * The order counts: the message cursor only reads what follows `ExecutionStarted`.
     *
     * @param list<string> $answers
     */
    private function answerUpFront(WorkflowTestEnvironment $environment, string $executionId, array $answers): void
    {
        $environment->getEventStore()->append(new ExecutionStarted($executionId, []));
        $environment->getEventStore()->append(new WorkflowSignalReceived(
            $executionId,
            'question_answered',
            ['callId' => self::CALL, 'answers' => $answers],
        ));
    }

    /**
     * The production shape: it is the definition loader that registers the `#[AsSignalMethod]`.
     * Instantiating the class inside a closure would leave them by the wayside, and no signal would
     * ever reach its condition.
     *
     * @return array<string, mixed>
     */
    private function input(?float $timeout = null): array
    {
        return [
            'prompt' => 'Import the catalogue',
            'maxTurns' => 1,
            'mode' => 'auto',
            'humanTimeoutSeconds' => $timeout,
        ];
    }

    /**
     * First turn: the model asks the question. Second turn: it reads back what it was answered.
     *
     * @return array<string, callable>
     */
    private function scriptedModel(): array
    {
        $round = 0;

        return [
            'ai_model_invoke' => static function (array $payload) use (&$round): array {
                if (0 === $round++) {
                    return self::toolCall(self::CALL, AskUserQuestion::TOOL, [
                        'question' => 'How do you want to import?',
                        'header' => 'Import',
                        'options' => [
                            ['label' => 'Batch', 'description' => 'All at once'],
                            ['label' => 'In the background', 'description' => 'Streaming'],
                        ],
                        'multiSelect' => true,
                    ]);
                }

                $last = end($payload['payload']['messages']);

                return self::text('Understood: '.$last['content']);
            },
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private static function toolCall(string $id, string $name, array $arguments): array
    {
        return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments, \JSON_UNESCAPED_UNICODE)],
        ]]], 'finish_reason' => 'tool_calls']]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(string $text): array
    {
        return ['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']]];
    }
}
