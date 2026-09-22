<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Infrastructure\Durable\ChatTranscript;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * "The agent is working" must mean that a turn is in progress — not that no turn has ever started.
 */
#[CoversClass(ChatTranscript::class)]
final class ChatTranscriptStatusTest extends TestCase
{
    private const EXECUTION = 'chat-1';

    private InMemoryEventStore $eventStore;
    private ChatTranscript $transcript;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $this->transcript = new ChatTranscript($this->eventStore, new InMemoryWorkflowMetadataStore());
    }

    /**
     * A dead execution no longer thinks: without this, the interface would wait forever.
     */
    public function testAFailedExecutionIsFinishedAndSaysWhy(): void
    {
        $this->eventStore->append(new \Gplanchat\Durable\Event\WorkflowSignalReceived(self::EXECUTION, 'user_message', ['text' => 'Hello']));
        $this->eventStore->append(\Gplanchat\Durable\Event\WorkflowExecutionFailed::unhandledActivityFailure(
            self::EXECUTION, 'a1', 'ai_model_invoke', new \RuntimeException('The provider replied 503'),
        ));

        $transcript = $this->transcript->forExecution(self::EXECUTION);

        self::assertTrue($transcript->finished);
        self::assertFalse($transcript->working);
        self::assertSame('The provider replied 503', $transcript->failure);
    }

    public function testAFreshExecutionIsIdleNotWorking(): void
    {
        self::assertFalse($this->transcript->forExecution(self::EXECUTION)->working);
    }

    public function testASignalledMessageTheModelHasNotSeenYetCountsAsWorking(): void
    {
        $this->eventStore->append(new WorkflowSignalReceived(self::EXECUTION, 'user_message', ['text' => 'Hi']));

        self::assertTrue($this->transcript->forExecution(self::EXECUTION)->working);
    }

    public function testAnAnsweredTurnIsIdleAgain(): void
    {
        $this->eventStore->append(new WorkflowSignalReceived(self::EXECUTION, 'user_message', ['text' => 'Hi']));
        $this->eventStore->append(new ActivityScheduled(self::EXECUTION, 'a1', 'ai_model_invoke', [
            'payload' => ['messages' => [['role' => 'user', 'content' => 'Hi']]],
        ]));
        $this->eventStore->append(new ActivityCompleted(self::EXECUTION, 'a1', [
            'choices' => [['message' => ['content' => 'Hello.']]],
        ]));

        $transcript = $this->transcript->forExecution(self::EXECUTION);

        self::assertFalse($transcript->working);
        self::assertSame('Hello.', $transcript->messages[1]->content);
    }

    public function testAScheduledModelCallWithoutItsResultIsWorking(): void
    {
        $this->eventStore->append(new WorkflowSignalReceived(self::EXECUTION, 'user_message', ['text' => 'Hi']));
        $this->eventStore->append(new ActivityScheduled(self::EXECUTION, 'a1', 'ai_model_invoke', [
            'payload' => ['messages' => [['role' => 'user', 'content' => 'Hi']]],
        ]));

        self::assertTrue($this->transcript->forExecution(self::EXECUTION)->working);
    }

    /**
     * The reasoning is read in two places, and both are needed: the one of a past turn is put back
     * by the normaliser into the payload of the next turn, the one of the last turn has no next turn
     * and lives only in the result.
     *
     * The shape is that of the Mistral bridge — `thinking` and `text` chunks inside `content` — and
     * not the `reasoning_content` of the generic contract, which Mistral refuses with a 422.
     */
    public function testTheThreadCarriesTheReasoningOfPastAndLastTurns(): void
    {
        $this->eventStore->append(new WorkflowSignalReceived(self::EXECUTION, 'user_message', ['text' => 'Weather in Lyon?']));
        $this->eventStore->append(new ActivityScheduled(self::EXECUTION, 'a1', 'ai_model_invoke', [
            'payload' => ['messages' => [
                ['role' => 'user', 'content' => 'Weather in Lyon?'],
                ['role' => 'assistant', 'content' => [
                    ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'A city is named, I read the weather.']]],
                ]],
                ['role' => 'tool', 'content' => 'Lyon: 25°C'],
            ]],
        ]));
        $this->eventStore->append(new ActivityCompleted(self::EXECUTION, 'a1', [
            'choices' => [['message' => ['content' => [
                ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'The reading is there, I answer.']]],
                ['type' => 'text', 'text' => 'Lyon 25°C.'],
            ]]]],
        ]));

        $messages = $this->transcript->forExecution(self::EXECUTION)->messages;

        self::assertSame('A city is named, I read the weather.', $messages[1]->reasoning, 'The reasoning of a past turn is lost.');
        self::assertNull($messages[0]->reasoning, 'A message from the human has no reasoning.');
        self::assertSame('Lyon 25°C.', $messages[3]->content);
        self::assertSame('The reading is there, I answer.', $messages[3]->reasoning, 'The reasoning of the last turn is lost.');
    }

    public function testAMessageWithoutReasoningCarriesNullNotAnEmptyString(): void
    {
        $this->eventStore->append(new ActivityScheduled(self::EXECUTION, 'a1', 'ai_model_invoke', [
            'payload' => ['messages' => [['role' => 'assistant', 'content' => 'Hello.', 'reasoning_content' => '   ']]],
        ]));

        self::assertNull($this->transcript->forExecution(self::EXECUTION)->messages[0]->reasoning);
    }
}
