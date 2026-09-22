<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Application\Chat\Transcript;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La preuve que la boucle avance dans un seul processus : pas de `messenger:consume`, pas de
 * cluster, seulement le worker en processus que la TUI appelle.
 */
final class ConversationLoopTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAMessageGetsAnAnswerThroughAReadTool(): void
    {
        [$conversations, $worker] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'Quel temps fait-il à Paris ?');
        $worker->drain();

        $transcript = $conversations->transcript($id);
        self::assertFalse($transcript->working);
        self::assertSame('Paris : 22°C, ensoleillé', $this->lastAssistant($transcript));
        self::assertSame('weather', $transcript->steps[0]->tool);
    }

    public function testAnExternalToolWaitsForApprovalThenRuns(): void
    {
        [$conversations, $worker] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'Envoie un mail à l’équipe');
        $worker->drain();

        $pending = $conversations->transcript($id)->pending;
        self::assertCount(1, $pending);
        self::assertSame('send_email', $pending[0]->tool);

        $conversations->decide($id, $pending[0]->callId, true);
        $worker->drain();

        self::assertSame('Courriel envoyé à equipe@example.test.', $this->lastAssistant($conversations->transcript($id)));
    }

    public function testAQuestionIsAnsweredByTheHuman(): void
    {
        [$conversations, $worker] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'Pose-moi une question');
        $worker->drain();

        $questions = $conversations->transcript($id)->questions;
        self::assertCount(1, $questions);

        $conversations->answer($id, $questions[0]->callId, ['La météo']);
        $worker->drain();

        self::assertSame('La météo', $this->lastAssistant($conversations->transcript($id)));
    }

    public function testAWatchSleepsUntilTheAlert(): void
    {
        [$conversations, $worker] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'Surveille la livraison');
        $worker->drain();

        $watches = $conversations->transcript($id)->watches;
        self::assertCount(1, $watches);
        self::assertSame('commande.expediee', $watches[0]->subject->value);

        $conversations->alert($id, $watches[0]->callId, 'le camion est à quai');
        $worker->drain();

        self::assertStringContainsString('le camion est à quai', (string) $this->lastAssistant($conversations->transcript($id)));
    }

    public function testADelegationGetsTheSubAgentAnswer(): void
    {
        [$conversations, $worker] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'Délègue la recherche');
        $worker->drain();

        $transcript = $conversations->transcript($id);
        self::assertFalse($transcript->working, 'Le parent attend encore son sous-agent.');
        self::assertStringContainsString('Le sous-agent', (string) $this->lastAssistant($transcript));
    }

    /**
     * Un outil en panne, tentatives épuisées, redevient un résultat que le modèle lit : la
     * conversation continue.
     */
    public function testAToolThatKeepsFailingIsReportedToTheModel(): void
    {
        [$conversations, $worker] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'Prends une note');
        $worker->drain();

        $transcript = $conversations->transcript($id);
        self::assertFalse($transcript->working);
        self::assertFalse($transcript->finished);
        self::assertSame('Échec de l\'outil « save_note » : Le disque est plein.', $this->lastAssistant($transcript));
    }

    /**
     * @return array{Conversations, InProcessWorker}
     */
    private function services(): array
    {
        $container = self::getContainer();

        return [$container->get(Conversations::class), $container->get(InProcessWorker::class)];
    }

    private function lastAssistant(Transcript $transcript): ?string
    {
        foreach (array_reverse($transcript->messages) as $message) {
            if ('assistant' === $message->role && null !== $message->content) {
                return $message->content;
            }
        }

        return null;
    }
}
