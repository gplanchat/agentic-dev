<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Identity\ConversationNotOwned;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A conversation belongs to whoever opened it, and knowing its identifier grants nothing.
 *
 * The identifier is a UUID, so this is not a lock against guessing: it is what stops an identifier
 * that legitimately circulated — a log line, a shared screen, an old link — from being enough to
 * read someone's thread or to speak in their name.
 */
final class ConversationOwnershipTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAnotherPrincipalCanNeitherSeeNorMoveTheConversation(): void
    {
        [$conversations, $worker, $principal] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'What is the weather in Paris?');
        $worker->drain();
        self::assertNotEmpty($conversations->transcript($id)->messages);

        $principal->becomes('bob');

        // Not "unknown": bob is told nothing at all, not even that the conversation exists.
        self::assertFalse($conversations->exists($id));
        self::assertSame([], $conversations->recent());

        $this->expectException(ConversationNotOwned::class);
        $conversations->transcript($id);
    }

    /**
     * The seven intents that move a conversation go through one gate; this walks all seven, so that
     * an eighth added without the gate is caught here rather than in production.
     */
    public function testEveryIntentIsRefusedToAnotherPrincipal(): void
    {
        [$conversations, $worker, $principal] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $principal->becomes('bob');

        $refused = [
            'send' => static fn () => $conversations->send($id, 'hello'),
            'decide' => static fn () => $conversations->decide($id, 'call-1', true),
            'answer' => static fn () => $conversations->answer($id, 'call-1', ['yes']),
            'alert' => static fn () => $conversations->alert($id, 'call-1', 'shipped'),
            'setMode' => static fn () => $conversations->setMode($id, AgentMode::Auto),
            'close' => static fn () => $conversations->close($id),
            'restart' => static fn () => $conversations->restart($id),
        ];

        foreach ($refused as $intent => $attempt) {
            try {
                $attempt();
                self::fail(\sprintf('"%s" went through for someone who does not own the conversation.', $intent));
            } catch (ConversationNotOwned $refusal) {
                self::assertSame($id, $refusal->conversation);
            }
        }

        // And nothing of all that reached the journal: the thread is as alice left it.
        $principal->becomes('alice');
        $worker->drain();
        self::assertSame([], $conversations->transcript($id)->messages);
    }

    public function testTheOwnerGetsTheirConversationBack(): void
    {
        [$conversations, $worker, $principal] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'What is the weather in Paris?');
        $worker->drain();

        $principal->becomes('bob');
        $bobs = $conversations->start();
        $worker->drain();

        // Each one lists their own, and only their own.
        self::assertSame([$bobs], array_column($conversations->recent(), 'id'));
        $principal->becomes('alice');
        self::assertSame([$id], array_column($conversations->recent(), 'id'));
        self::assertTrue($conversations->exists($id));
    }

    /**
     * @return array{Conversations, InProcessWorker, SwitchablePrincipal}
     */
    private function services(): array
    {
        $container = self::bootKernel()->getContainer();
        $conversations = $container->get(Conversations::class);
        $worker = $container->get(InProcessWorker::class);
        $principal = $container->get(SwitchablePrincipal::class);

        // The container hands back `object`: asserting is what gives the three their type, and it
        // says so out loud the day the wiring stops matching.
        self::assertInstanceOf(Conversations::class, $conversations);
        self::assertInstanceOf(InProcessWorker::class, $worker);
        self::assertInstanceOf(SwitchablePrincipal::class, $principal);

        return [$conversations, $worker, $principal];
    }
}
