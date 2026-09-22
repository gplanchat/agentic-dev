<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\AgenticBundle\Tui\BananaWords;
use Gplanchat\AgenticBundle\Tui\ChatScreen;
use Gplanchat\AgenticBundle\Tui\ChatView;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ChatViewTest extends KernelTestCase
{
    private VirtualTerminal $terminal;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAMessageTypedInTheTerminalGetsAnAnswer(): void
    {
        $view = $this->open();

        $this->type($view, "Quel temps fait-il à Paris ?\r");

        self::assertStringContainsString('Paris : 22°C, ensoleillé', $this->terminal->getOutput());
    }

    public function testAnApprovalIsGivenFromTheList(): void
    {
        $view = $this->open();

        $this->type($view, "Envoie un mail à l’équipe\r");
        self::assertStringContainsString('Approuver', $this->terminal->getOutput());

        // Le premier choix est « Approuver ».
        $this->type($view, "\r");
        self::assertStringContainsString('Courriel envoyé à equipe@example.test.', $this->terminal->getOutput());
    }

    public function testAQuestionIsAnsweredFromTheList(): void
    {
        $view = $this->open();

        $this->type($view, "Pose-moi une question\r");
        self::assertStringContainsString('Sur quoi veux-tu que je tranche ?', $this->terminal->getOutput());

        $this->type($view, "\r");
        self::assertStringContainsString('La météo', $this->terminal->consumeOutput());
    }

    public function testASlashCommandIsNotSentToTheAgent(): void
    {
        $view = $this->open();

        $this->type($view, "/tools\r");

        $output = $this->terminal->getOutput();
        self::assertMatchesRegularExpression('/weather\s+read\s+passe/', $output);
        self::assertSame([], self::getContainer()->get(Conversations::class)->transcript($view->conversation)->messages);
    }

    public function testTabCompletesTheCommandName(): void
    {
        $view = $this->open();

        $this->type($view, "/to\t");

        self::assertStringContainsString('› /tools ', $this->terminal->getOutput());
    }

    public function testClearSwitchesTheScreenToANewConversation(): void
    {
        $view = $this->open();
        $first = $view->conversation;

        $this->type($view, "/clear\r");

        self::assertNotSame($first, $view->conversation);
        self::assertStringContainsString(substr($view->conversation, 0, 8), $this->terminal->getOutput());
    }

    public function testShiftTabRotatesTheModeShownAtTheBottom(): void
    {
        $view = $this->open();
        $conversations = self::getContainer()->get(Conversations::class);
        self::assertStringContainsString('● mode standard', $this->terminal->consumeOutput());

        foreach (['edition', 'auto', 'standard'] as $expected) {
            // Une séquence d'échappement arrive d'un bloc, pas caractère par caractère.
            $this->terminal->simulateInput("\e[Z");
            $view->refresh();
            $view->tui->tick();

            self::assertSame($expected, $conversations->transcript($view->conversation)->mode->value);
            self::assertStringContainsString('● mode '.$expected, $this->terminal->consumeOutput());
        }
    }

    public function testTheBananaDancesInTheHeader(): void
    {
        $view = $this->open();
        $before = $this->terminal->consumeOutput();
        self::assertStringContainsString('Agentic', AnsiUtils::stripAnsiCodes($before));

        $view->dance();
        $view->tui->tick();

        self::assertNotSame('', $this->terminal->consumeOutput(), 'Un temps de danse redessine l’en-tête.');
    }

    public function testTheWheelScrollsTheThreadNotTheTerminal(): void
    {
        $view = $this->open();
        foreach (['Paris', 'Lyon', 'Marseille', 'Paris', 'Lyon', 'Marseille'] as $city) {
            $this->type($view, "Quel temps fait-il à $city ?\r");
        }
        $this->terminal->consumeOutput();

        $this->key($view, "\e[<64;10;10M");
        self::assertStringContainsString('lignes plus récentes', AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput()));

        $this->key($view, "\e[<65;10;10M");
        self::assertStringNotContainsString('plus récentes', AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput()));
    }

    public function testAClickNeverReachesTheInput(): void
    {
        $view = $this->open();

        $this->key($view, "\e[<0;12;3M");
        $this->type($view, "\r");

        self::assertSame([], self::getContainer()->get(Conversations::class)->transcript($view->conversation)->messages);
    }

    public function testPageUpScrollsToo(): void
    {
        $view = $this->open();
        foreach (['Paris', 'Lyon', 'Marseille', 'Paris', 'Lyon', 'Marseille'] as $city) {
            $this->type($view, "Quel temps fait-il à $city ?\r");
        }
        $this->terminal->consumeOutput();

        $this->key($view, "\e[5~");

        self::assertStringContainsString('lignes plus récentes', AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput()));
    }

    /**
     * Comme un shell : ↑ remonte, ↓ redescend, et on retrouve ce qu'on était en train de taper.
     */
    public function testArrowsRecallPreviousMessagesAndKeepTheDraft(): void
    {
        $view = $this->open();
        $this->type($view, "Quel temps fait-il à Paris ?\r");
        $this->type($view, "/tools\r");
        $this->type($view, 'brouillon');

        $this->key($view, "\e[A");
        self::assertStringContainsString('› /tools', $this->terminal->consumeOutput());

        $this->key($view, "\e[A");
        self::assertStringContainsString('› Quel temps fait-il à Paris ?', $this->terminal->consumeOutput());

        $this->key($view, "\e[B");
        $this->key($view, "\e[B");
        self::assertStringContainsString('› brouillon', $this->terminal->consumeOutput());

        // Rappeler n'envoie rien : seul Entrée envoie.
        self::assertCount(1, array_filter(
            self::getContainer()->get(Conversations::class)->transcript($view->conversation)->messages,
            static fn ($message): bool => $message->isUser(),
        ));
    }

    /**
     * Le message s'affiche avant que le worker ne l'ait traité : l'humain sait qu'il est parti, et
     * la banane dit qu'elle s'y met.
     */
    public function testASentMessageShowsAtOnceBeforeTheAgentAnswers(): void
    {
        $view = $this->open();

        foreach (mb_str_split("Quel temps fait-il à Paris ?\r") as $key) {
            $this->terminal->simulateInput($key);
        }
        $view->tui->tick();

        $shown = AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput());
        self::assertStringContainsString('› Quel temps fait-il à Paris ?  ✓ envoyé', $shown);
        self::assertMatchesRegularExpression('/('.implode('|', array_map('preg_quote', BananaWords::PHRASES)).')… \(\d+ s\)/u', $shown);
        self::assertStringNotContainsString('22°C', $shown, 'Le worker n’a pas encore tourné.');

        $view->refresh();
        $view->tui->tick();

        $shown = AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput());
        self::assertStringContainsString('Paris : 22°C, ensoleillé', $shown);
        self::assertStringNotContainsString('✓ envoyé', $shown, 'Une fois au journal, le message n’est plus « en route ».');
    }

    public function testAnApprovalIsConfirmedOnScreen(): void
    {
        $view = $this->open();
        $this->type($view, "Envoie un mail à l’équipe\r");

        $this->type($view, "\r");

        self::assertStringContainsString('✓ Approuvé — send_email', AnsiUtils::stripAnsiCodes($this->terminal->getOutput()));
    }

    public function testRewindOffersAListAndPutsTheMessageBackInTheInput(): void
    {
        $view = $this->open();
        $first = $view->conversation;
        $this->type($view, "Quel temps fait-il à Paris ?\r");

        $this->type($view, "/rewind\r");
        self::assertMatchesRegularExpression('/n° 1\s+Quel temps fait-il à Paris \?/u', AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput()));

        $this->type($view, "\r");

        self::assertNotSame($first, $view->conversation);
        self::assertStringContainsString('› Quel temps fait-il à Paris ?', AnsiUtils::stripAnsiCodes($this->terminal->getOutput()));
        self::assertSame([], self::getContainer()->get(Conversations::class)->transcript($view->conversation)->userMessages(), 'Rien n’est renvoyé tant qu’on n’appuie pas sur Entrée.');
    }

    public function testCtrlCQuitsWithoutClosingTheConversation(): void
    {
        $view = $this->open();

        $this->type($view, "\x03");

        self::assertFalse($view->tui->isRunning());
        self::assertFalse(self::getContainer()->get(Conversations::class)->transcript($view->conversation)->finished);
    }

    private function open(): ChatView
    {
        $this->terminal = new VirtualTerminal(100, 30);
        $container = self::getContainer();
        $view = $container->get(ChatScreen::class)->open($container->get(Conversations::class)->start(), $this->terminal);
        $view->tui->start();
        $view->refresh();
        $view->tui->tick();

        return $view;
    }

    /**
     * Une séquence d'échappement arrive d'un bloc, pas caractère par caractère.
     */
    private function key(ChatView $view, string $sequence): void
    {
        $this->terminal->simulateInput($sequence);
        $view->refresh();
        $view->tui->tick();
    }

    private function type(ChatView $view, string $keys): void
    {
        foreach (mb_str_split($keys) as $key) {
            $this->terminal->simulateInput($key);
        }
        $view->refresh();
        $view->tui->tick();
    }
}
