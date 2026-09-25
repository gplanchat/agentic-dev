<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Domain\Mikado\MikadoGraph;
use Gplanchat\Agentic\Domain\Mikado\MikadoTool;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Agentic\Infrastructure\Durable\ChatTranscript;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\VersionMarked;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The Mikado graph of the task under way: workflow state, changed by tools the workflow runs
 * itself, journaled at every change, carried from run to run.
 */
final class MikadoWorkflowTest extends TestCase
{
    public function testTheGraphIsKeptByTheWorkflowAndJournaledOncePerChange(): void
    {
        $seen = [];
        $toolCalls = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => self::script([
                // Two in one turn: both run, in order.
                self::calls(['c1', MikadoTool::START, ['goal' => 'Move to PHP 8.4', 'ticket' => 45]], ['c2', MikadoTool::NOTE, ['for' => 'M1', 'prerequisite' => 'Drop lib X']]),
                self::call('c5', 'weather', ['city' => 'Paris']),
                self::call('c3', MikadoTool::SHOW, []),
                self::call('c4', MikadoTool::DONE, ['node' => 'M1']),
                self::say('noted'),
            ], $seen),
            'ai_tool_call' => static function () use (&$toolCalls): string {
                ++$toolCalls;

                return 'ran';
            },
        ]);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, ['prompt' => 'Go', 'mode' => 'plan', 'maxTurns' => 1, 'tools' => ['weather' => ['description' => 'Weather', 'effect' => 'read']]], 'mikado-1');

        self::assertSame(1, $toolCalls, 'One activity, the weather\'s: the graph is workflow state, and the other tools still run as they did.');
        self::assertSame('ran', self::toolResult($seen[2], 'c5'));
        self::assertContains('mikado_note', $seen[0]['tools'], 'Offered to a new conversation, even in plan mode.');
        self::assertSame("Mikado graph of the work ticket #45\nM1 Move to PHP 8.4 [READY]", self::toolResult($seen[1], 'c1'));
        self::assertSame("Mikado graph of the work ticket #45\nM1 Move to PHP 8.4 [open]\n  M2 Drop lib X [READY]", self::toolResult($seen[1], 'c2'));
        self::assertSame("Mikado graph of the work ticket #45\nM1 Move to PHP 8.4 [open]\n  M2 Drop lib X [READY]", self::toolResult($seen[3], 'c3'));
        self::assertSame('M1 still requires M2: do those first.', self::toolResult($seen[4], 'c4'));

        $recorded = [];
        foreach ($environment->getEventStore()->readStream('mikado-1') as $event) {
            if ($event instanceof SideEffectRecorded) {
                $recorded[] = $event->result();
            }
        }
        self::assertSame([
            ['mikado' => MikadoGraph::start('Move to PHP 8.4', 45)->toWire()],
            ['mikado' => MikadoGraph::start('Move to PHP 8.4', 45)->note(1, 'Drop lib X')->toWire()],
        ], $recorded, 'Once per change, whatever the replays: neither the show nor the refusal is one.');

        $transcript = (new ChatTranscript($environment->getEventStore(), new InMemoryWorkflowMetadataStore()))->forExecution('mikado-1');
        self::assertEquals(MikadoGraph::start('Move to PHP 8.4', 45)->note(1, 'Drop lib X'), $transcript->mikado, 'What the next run will be handed.');
    }

    public function testATranscriptReadsTheGraphItWasStartedWithUntilItRecordsOne(): void
    {
        $events = new InMemoryEventStore();
        $metadata = new InMemoryWorkflowMetadataStore();
        $events->append(new ExecutionStarted('carried', ['mikado' => MikadoGraph::start('G')->toWire()]));
        $transcripts = new ChatTranscript($events, $metadata);

        self::assertEquals(MikadoGraph::start('G'), $transcripts->forExecution('carried')->mikado);

        $events->append(new SideEffectRecorded('carried', 's1', ['something' => 'else']));
        $events->append(new SideEffectRecorded('carried', 's2', ['mikado' => MikadoGraph::start('G')->note(1, 'P')->toWire()]));
        $events->append(new SideEffectRecorded('carried', 's3', 'not a graph'));
        self::assertEquals(MikadoGraph::start('G')->note(1, 'P'), $transcripts->forExecution('carried')->mikado);

        $events->append(new ExecutionStarted('none', []));
        self::assertNull($transcripts->forExecution('none')->mikado);
    }

    public function testTheRelayCarriesTheGraphAndTheNextRunIsToldOfIt(): void
    {
        $seen = [];
        $environment = WorkflowTestEnvironment::inMemory(['ai_model_invoke' => self::script([
            self::call('c1', MikadoTool::START, ['goal' => 'Move to PHP 8.4']),
            self::say('started'),
        ], $seen)]);

        try {
            $environment->runWorkflowClass(DurableAgentWorkflow::class, ['prompt' => 'Go', 'maxTurns' => 2, 'rolloverAfterTurns' => 1, 'tools' => ['weather' => ['description' => 'Weather', 'effect' => 'read']]], 'mikado-2');
            self::fail('No relay.');
        } catch (ContinueAsNewRequested $relay) {
            self::assertSame(['weather' => ['description' => 'Weather', 'effect' => 'read']], $relay->payload['tools'] ?? null, 'The relay still carries what it always did.');
            self::assertSame(MikadoGraph::start('Move to PHP 8.4')->toWire(), $relay->payload['mikado'] ?? null);
        }

        $next = [];
        $environment = WorkflowTestEnvironment::inMemory(['ai_model_invoke' => self::script([self::say('going on')], $next)]);
        $environment->runWorkflowClass(DurableAgentWorkflow::class, ['prompt' => 'Go on', 'maxTurns' => 1, 'systemPrompt' => 'Be brief.', 'mikado' => $relay->payload['mikado']], 'mikado-3');

        self::assertSame("Be brief.\n\n# The task under way\n\nYou were conducting it with the Mikado method; its graph so far:\n\nMikado graph\nM1 Move to PHP 8.4 [READY]", $next[0]['system']);
    }

    public function testWithoutAGraphUnderWayThePayloadAndThePromptAreAsBefore(): void
    {
        $seen = [];
        $environment = WorkflowTestEnvironment::inMemory(['ai_model_invoke' => self::script([self::say('hi')], $seen)]);

        try {
            $environment->runWorkflowClass(DurableAgentWorkflow::class, ['prompt' => 'Go', 'maxTurns' => 2, 'rolloverAfterTurns' => 1, 'systemPrompt' => 'Be brief.'], 'mikado-4');
            self::fail('No relay.');
        } catch (ContinueAsNewRequested $relay) {
            self::assertArrayNotHasKey('mikado', $relay->payload, 'No graph, no key: the payload keeps its old shape.');
        }
        self::assertSame('Be brief.', $seen[0]['system']);

        $finished = [];
        $environment = WorkflowTestEnvironment::inMemory(['ai_model_invoke' => self::script([self::say('hi')], $finished)]);
        $environment->runWorkflowClass(DurableAgentWorkflow::class, ['prompt' => 'Go', 'maxTurns' => 1, 'systemPrompt' => 'Be brief.', 'mikado' => MikadoGraph::start('G')->done(1)->toWire()], 'mikado-5');
        self::assertSame('Be brief.', $finished[0]['system'], 'A finished task is not under way.');
    }

    /**
     * The graph is the caller's task: a delegate conducts its mission from scratch. And the slot is
     * the last of `run()` — a delegate that received the caller's graph would be told of it here.
     */
    public function testADelegateStartsWithoutItsCallersGraph(): void
    {
        $seen = [];
        $environment = WorkflowTestEnvironment::inMemory(['ai_model_invoke' => self::script([
            self::call('c1', 'delegate', ['mission' => 'Look around']),
            self::say('looked'),
            self::say('done'),
        ], $seen)]);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, [
            'prompt' => 'Delegate',
            'maxTurns' => 1,
            'mode' => 'auto',
            'systemPrompt' => 'Parent.',
            'mikado' => MikadoGraph::start('Move to PHP 8.4')->toWire(),
        ], 'mikado-6');

        self::assertStringContainsString('# The task under way', $seen[0]['system']);
        self::assertSame(DurableAgentWorkflow::SYSTEM_PROMPT, $seen[1]['system'], 'The child was not told of its caller\'s graph.');
        self::assertContains('mikado_start', $seen[1]['tools'], 'It may keep a graph of its own.');
    }

    /**
     * The change point. Durable compares every replayed activity's payload with the journal's, and
     * the Mikado tools are in every model call's payload: a conversation begun before them must
     * replay without them — or it fails on its next resume, weeks after it began.
     *
     * The journal "from before" is a real one with the Mikado tools and the version marker taken
     * out. The same journal with its marker left in must diverge: that is what shows the test can
     * tell the two apart.
     */
    public function testAConversationBegunBeforeTheToolsReplaysWithoutThem(): void
    {
        $seen = [];
        $recording = WorkflowTestEnvironment::inMemory(['ai_model_invoke' => self::script([self::say('hi')], $seen)]);
        $answer = $recording->runWorkflowClass(DurableAgentWorkflow::class, ['prompt' => 'Go', 'maxTurns' => 1], 'before-mikado');
        $journal = iterator_to_array($recording->getEventStore()->readStream('before-mikado'), false);
        $marks = array_values(array_filter($journal, static fn (Event $event): bool => $event instanceof VersionMarked));
        self::assertCount(1, $marks, 'A new conversation takes the new side.');
        self::assertSame(['changeId' => 'mikado-tools', 'version' => 1], ['changeId' => $marks[0]->changeId(), 'version' => $marks[0]->version()]);

        $calls = 0;
        $replay = WorkflowTestEnvironment::inMemory(['ai_model_invoke' => static function () use (&$calls): array {
            ++$calls;

            return self::say('not from the journal');
        }]);
        foreach ($journal as $event) {
            if (!$event instanceof VersionMarked) {
                $replay->getEventStore()->append(self::withoutMikado($event));
            }
        }

        self::assertSame($answer, $replay->runWorkflowClass(DurableAgentWorkflow::class, ['prompt' => 'Go', 'maxTurns' => 1], 'before-mikado'));
        self::assertSame(0, $calls, 'Replayed from the journal, not asked again.');

        $marked = WorkflowTestEnvironment::inMemory(['ai_model_invoke' => static fn (): array => self::say('x')]);
        foreach ($journal as $event) {
            $marked->getEventStore()->append(self::withoutMikado($event));
        }
        $this->expectExceptionMessage('Replay divergence');
        $marked->runWorkflowClass(DurableAgentWorkflow::class, ['prompt' => 'Go', 'maxTurns' => 1], 'before-mikado');
    }

    private static function withoutMikado(Event $event): Event
    {
        if (!$event instanceof ActivityScheduled || 'ai_model_invoke' !== $event->activityName()) {
            return $event;
        }
        $strip = static function (mixed $value) use (&$strip): mixed {
            if (!\is_array($value)) {
                return $value;
            }
            $kept = array_filter($value, static fn (mixed $item): bool => !str_starts_with((string) (\is_array($item) ? ($item['function']['name'] ?? '') : ''), 'mikado_'));

            return array_map($strip, array_is_list($value) ? array_values($kept) : $kept);
        };

        // `payload()` is the event's envelope; the activity's own arguments sit under its `payload`.
        return new ActivityScheduled($event->executionId(), $event->activityId(), $event->activityName(), $strip($event->payload()['payload']), $event->metadata());
    }

    /**
     * @param list<array<string, mixed>>                                   $answers one per model call, in order
     * @param list<array{system: string, tools: list<string>, messages: list<array<string, mixed>>}> $seen
     */
    private static function script(array $answers, array &$seen): \Closure
    {
        return static function (array $payload) use (&$answers, &$seen): array {
            $messages = $payload['payload']['messages'] ?? [];
            $seen[] = [
                'system' => (string) ($messages[0]['content'] ?? ''),
                'tools' => array_map(static fn (array $tool): string => (string) $tool['function']['name'], $payload['options']['tools'] ?? []),
                'messages' => $messages,
            ];

            return array_shift($answers) ?? self::say('(script exhausted)');
        };
    }

    /**
     * @param array{messages: list<array<string, mixed>>} $call
     */
    private static function toolResult(array $call, string $id): ?string
    {
        foreach ($call['messages'] as $message) {
            if ('tool' === ($message['role'] ?? null) && $id === ($message['tool_call_id'] ?? null)) {
                return (string) $message['content'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private static function call(string $id, string $tool, array $arguments): array
    {
        return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $tool, 'arguments' => json_encode((object) $arguments)],
        ]]], 'finish_reason' => 'tool_calls']]];
    }

    /**
     * @param array{0: string, 1: string, 2: array<string, mixed>} ...$calls id, tool, arguments
     *
     * @return array<string, mixed>
     */
    private static function calls(array ...$calls): array
    {
        return ['choices' => [['message' => ['content' => null, 'tool_calls' => array_map(static fn (array $call): array => [
            'id' => $call[0],
            'type' => 'function',
            'function' => ['name' => $call[1], 'arguments' => json_encode((object) $call[2])],
        ], $calls)], 'finish_reason' => 'tool_calls']]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function say(string $text): array
    {
        return ['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']]];
    }
}
