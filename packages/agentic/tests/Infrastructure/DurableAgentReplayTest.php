<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Application\Chat\AgentOutcome;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The question of the prototype: does Symfony AI's tool-calling loop survive replay?
 *
 * The in-memory runner runs in distributed mode: every `await` suspends the fiber and **replays the
 * workflow code from the start** on resume. `Runner::run()` is therefore re-executed once per
 * suspension point — if the loop were not replayable, this test would not go all the way.
 */
#[CoversClass(DurableAgentWorkflow::class)]
final class DurableAgentReplayTest extends TestCase
{
    private const TOOLS = [
        'weather' => [
            'description' => 'Current weather of a city.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['city' => ['type' => 'string']],
                'required' => ['city'],
            ],
        ],
    ];

    public function testTheToolCallingLoopReplaysWithoutReinvokingTheModel(): void
    {
        [$result, $modelCalls, $toolCalls, $passes] = $this->executeAgent('exec-1');

        self::assertSame('Paris 22°C, Lyon 25°C.', AgentOutcome::fromWire($result)->answer);

        // Without this, the rest proves nothing: the workflow code — hence `Runner::run()` and the
        // tool-calling loop — must really have been re-executed.
        self::assertGreaterThan(3, $passes, 'The workflow was not replayed; the test proves nothing.');

        // The journal short-circuits the replay: three model turns, not one more, while the workflow
        // code was re-executed on every resume.
        self::assertCount(3, $modelCalls, 'The replay asked the model again.');
        self::assertCount(2, $toolCalls, 'The replay ran a tool again.');
    }

    public function testTheOutboundPayloadsAreIdenticalAcrossTwoIndependentRuns(): void
    {
        [, $first] = $this->executeAgent('exec-a');
        [, $second] = $this->executeAgent('exec-b');

        // The assertion that counts. The journal is indexed by cursor position, not by content: the
        // counts above would pass even if the payload had changed. A message UUID leaking into the
        // payload, a toolbox read back from the container, and this is where it goes red.
        self::assertSame(
            json_encode($first, \JSON_PRETTY_PRINT),
            json_encode($second, \JSON_PRETTY_PRINT),
            'The workflow code is not deterministic: two executions produce different payloads.',
        );
    }

    /**
     * A tool-calling turn **cannot** carry reasoning through this bridge, and that is a constraint
     * of the provider, not an oversight: `CompletionsConversionTrait::convertChoice()` returns a
     * bare `ToolCallResult` as soon as `finish_reason` is `tool_calls`, without looking at anything
     * else. The reasoning of a tooled turn is therefore lost at the boundary.
     *
     * The test is there so that it shows the day the bridge changes its mind: it is a change point,
     * not a detail — fixing the converter would make every in-flight execution diverge.
     */
    public function testAToolCallingTurnCarriesNoReasoningThroughThisBridge(): void
    {
        [, $modelCalls] = $this->executeAgent('exec-tool-reasoning');

        $assistantTurns = array_filter(
            $modelCalls[2]['payload']['messages'],
            static fn (array $m): bool => 'assistant' === ($m['role'] ?? null),
        );

        self::assertNotSame([], $assistantTurns, 'No assistant turn went back out to the model.');
        foreach ($assistantTurns as $turn) {
            self::assertArrayNotHasKey('reasoning_content', $turn, 'The generic contract took over: Mistral answers 422 on that.');
        }
    }

    /**
     * A reasoned turn arrives as a `MultiPartResult` — the Mistral converter returns a
     * `ThinkingResult` **and** a `TextResult`. `getContent()` gives an array there: without
     * `asText()`, the thread would display "Array" in place of the reply.
     */
    public function testTheAnswerStaysTheTextEvenWhenTheTurnCarriesReasoning(): void
    {
        [$result] = $this->executeAgent('exec-answer');

        self::assertSame('Paris 22°C, Lyon 25°C.', AgentOutcome::fromWire($result)->answer);
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3: int}
     */
    private function executeAgent(string $executionId): array
    {
        $modelCalls = [];
        $toolCalls = [];
        $passes = 0;

        $scripted = [
            $this->toolCallResponse('call_1', 'weather', ['city' => 'Paris']),
            $this->toolCallResponse('call_2', 'weather', ['city' => 'Lyon']),
            $this->textResponse('Paris 22°C, Lyon 25°C.', 'Both readings are there, I answer.'),
        ];

        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$modelCalls, $scripted): array {
                $modelCalls[] = ['model' => $payload['model'], 'payload' => $payload['payload'], 'options' => $payload['options']];

                return $scripted[\count($modelCalls) - 1];
            },
            'ai_tool_call' => static function (array $payload) use (&$toolCalls): string {
                $toolCalls[] = $payload;

                return \sprintf('%s: 22°C', $payload['arguments']['city']);
            },
        ]);

        $result = $environment->run(
            static function ($workflowEnvironment) use (&$passes): array {
                ++$passes;

                return (new DurableAgentWorkflow($workflowEnvironment))->run(
                    self::TOOLS,
                    prompt: 'Weather in Paris and in Lyon?',
                    mode: 'auto',
                    maxTurns: 1,
                );
            },
            $executionId,
        );

        return [$result, $modelCalls, $toolCalls, $passes];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function toolCallResponse(string $id, string $name, array $arguments): array
    {
        return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
        ]]], 'finish_reason' => 'tool_calls']]];
    }

    /**
     * @return array<string, mixed>
     */
    private function textResponse(string $text, string $reasoning = ''): array
    {
        return ['choices' => [['message' => [
            'content' => '' === $reasoning ? $text : [
                ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => $reasoning]]],
                ['type' => 'text', 'text' => $text],
            ],
        ], 'finish_reason' => 'stop']]];
    }
}
