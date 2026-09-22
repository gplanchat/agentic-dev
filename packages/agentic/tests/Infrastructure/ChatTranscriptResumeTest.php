<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Infrastructure\Durable\ChatTranscript;
use Gplanchat\Agentic\Application\Chat\Transcript;
use Gplanchat\Agentic\Application\Chat\TranscriptMessage;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An execution that resumes another one — closing on inactivity, or continue-as-new — carries its
 * thread in its start payload. As long as no model call has re-emitted it, that is the only trace
 * the journal has of it.
 */
#[CoversClass(ChatTranscript::class)]
#[CoversClass(Transcript::class)]
#[CoversClass(TranscriptMessage::class)]
final class ChatTranscriptResumeTest extends TestCase
{
    private const EXECUTION = 'chat-resumed';

    /** @var list<array{role: string, content: string}> */
    private const HISTORY = [
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hello there!'],
    ];

    private InMemoryEventStore $eventStore;
    private InMemoryWorkflowMetadataStore $metadataStore;
    private ChatTranscript $transcript;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $this->metadataStore = new InMemoryWorkflowMetadataStore();
        $this->transcript = new ChatTranscript($this->eventStore, $this->metadataStore);
    }

    public function testTheCarriedThreadShowsBeforeAnyModelCall(): void
    {
        $this->metadataStore->save(self::EXECUTION, 'Ai_DurableAgent', ['history' => self::HISTORY]);

        $transcript = $this->transcript->forExecution(self::EXECUTION);

        self::assertSame(
            ['Hello', 'Hello there!'],
            array_map(static fn (TranscriptMessage $message): ?string => $message->content, $transcript->messages),
        );
        self::assertFalse($transcript->working, 'a carried thread is not a turn in progress');
    }

    /**
     * Without counting the carried thread on the received-messages side, a resumed run looks idle
     * while it is working: the first signal of that run alone is no match for the whole bag.
     */
    public function testASignalOnAResumedRunStillCountsAsWorking(): void
    {
        $this->metadataStore->save(self::EXECUTION, 'Ai_DurableAgent', ['history' => self::HISTORY]);
        $this->eventStore->append(new WorkflowSignalReceived(self::EXECUTION, 'user_message', ['text' => 'And Lyon?']));

        self::assertTrue($this->transcript->forExecution(self::EXECUTION)->working);
    }

    /**
     * After a continue-as-new, the metadata store still carries the payload of the **first** run.
     * Only the event of the current run says what this run resumed.
     */
    public function testTheRunEventWinsOverTheStaleMetadataPayload(): void
    {
        $this->metadataStore->save(self::EXECUTION, 'Ai_DurableAgent', ['history' => []]);
        $this->eventStore->append(new ExecutionStarted(self::EXECUTION, ['history' => self::HISTORY]));

        $transcript = $this->transcript->forExecution(self::EXECUTION);

        self::assertCount(2, $transcript->messages);
        self::assertSame('Hello there!', $transcript->messages[1]->content);
    }

    /**
     * The journal of the current run wins as soon as it carries the thread: the start payload is
     * only a fallback.
     */
    public function testAModelCallSupersedesTheCarriedThread(): void
    {
        $this->metadataStore->save(self::EXECUTION, 'Ai_DurableAgent', ['history' => self::HISTORY]);
        $this->eventStore->append(new WorkflowSignalReceived(self::EXECUTION, 'user_message', ['text' => 'And Lyon?']));
        $this->eventStore->append(new ActivityScheduled(self::EXECUTION, 'a1', 'ai_model_invoke', [
            'payload' => ['messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hello there!'],
                ['role' => 'user', 'content' => 'And Lyon?'],
            ]],
        ]));

        self::assertCount(3, $this->transcript->forExecution(self::EXECUTION)->messages);
    }

    /**
     * What starts again is the spoken thread. A `role: tool` and an assistant message that carries
     * nothing but `tool_calls` tell the mechanics of the run that is ending: replaying them would
     * fill the next one with empty bubbles and with references to calls that no longer exist.
     */
    public function testTheSeedDropsToolNoise(): void
    {
        $this->eventStore->append(new ActivityScheduled(self::EXECUTION, 'a1', 'ai_model_invoke', [
            'payload' => ['messages' => [
                ['role' => 'system', 'content' => 'You are concise.'],
                ['role' => 'user', 'content' => 'Weather in Lyon?'],
                ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                    ['id' => 'c1', 'function' => ['name' => 'weather', 'arguments' => '{"city":"Lyon"}']],
                ]],
                ['role' => 'tool', 'content' => '18 °C'],
                ['role' => 'assistant', 'content' => 'It is 18 °C.'],
            ]],
        ]));

        self::assertSame([
            ['role' => 'user', 'content' => 'Weather in Lyon?'],
            ['role' => 'assistant', 'content' => 'It is 18 °C.'],
        ], $this->transcript->forExecution(self::EXECUTION)->seed());
    }
    /**
     * A summary can arrive as `thinking`/`text` chunks — that is the shape of a model that reasons,
     * and compaction comes through the same door as the rest.
     *
     * The projection used to read the raw `content` and keep only what was **already a string**: a
     * chunked summary was ignored, and the resumed thread was displayed in full while the model, for
     * its part, would only see the summary. {@see \Gplanchat\Agentic\Infrastructure\SymfonyAi\ChatCompletion} does the same
     * splitting on both sides.
     */
    public function testAChunkedDigestIsReadLikeAnyOtherAnswer(): void
    {
        $this->metadataStore->save(self::EXECUTION, 'Ai_DurableAgent', ['history' => self::HISTORY]);
        $this->eventStore->append(new ActivityScheduled(self::EXECUTION, 'c1', 'ai_model_compact', []));
        $this->eventStore->append(new ActivityCompleted(self::EXECUTION, 'c1', [
            'choices' => [['message' => ['content' => [
                ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'I weigh up what matters.']]],
                ['type' => 'text', 'text' => 'We said hello to each other.'],
            ]]]],
        ]));

        $transcript = $this->transcript->forExecution(self::EXECUTION);

        self::assertSame(
            ['Summary of our previous conversation: We said hello to each other.'],
            array_map(static fn (TranscriptMessage $message): ?string => $message->content, $transcript->messages),
        );
    }
}
