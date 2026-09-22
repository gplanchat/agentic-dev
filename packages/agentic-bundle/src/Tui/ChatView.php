<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Application\Chat\Transcript;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\MultiSelectEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

/**
 * L'écran de chat : le fil, puis ce que la conversation attend de l'humain — un message, une
 * validation, une réponse, une alerte.
 *
 * L'écran n'a pas d'état de conversation à lui : il relit la projection du journal à chaque
 * rafraîchissement, après avoir fait avancer le worker. Quitter (Ctrl+C) ne clôt pas la
 * conversation ; Ctrl+X la clôt ; Shift+Tab fait tourner le mode, affiché en bas. La molette et
 * Pg.Préc/Pg.Suiv font défiler le fil ; ↑/↓ rappellent les messages déjà envoyés, comme un shell. Une ligne qui commence par `/` est une commande
 * ({@see SlashCommands}), pas un message.
 */
final class ChatView
{
    private const REFRESH_SECONDS = 0.25;

    /** Le tempo de la banane. */
    private const BEAT_SECONDS = 0.4;

    /** Lignes parcourues par cran de molette. */
    private const WHEEL_LINES = 3;

    /**
     * Le terminal envoie la molette à l'application plutôt qu'à son propre défilement : mode 1000
     * (clics et molette, pas les mouvements), codage SGR 1006. Rétabli à la sortie.
     */
    private const MOUSE_ON = "\e[?1000h\e[?1006h";

    private const MOUSE_OFF = "\e[?1006l\e[?1000l";

    /**
     * L'écran alternatif, celui de vim ou de less : pas d'historique de défilement propre au
     * terminal, l'écran d'avant rendu tel quel à la sortie. Le curseur est ramené en haut à gauche,
     * d'où le rendu part.
     */
    private const SCREEN_ON = "\e[?1049h\e[H";

    private const SCREEN_OFF = "\e[?1049l";

    public readonly Tui $tui;

    private readonly TextWidget $header;

    private readonly ThreadWidget $thread;

    private readonly ContainerWidget $interaction;

    /** Ce que rend une commande, ou les commandes qui correspondent à ce qui est tapé. */
    private readonly TextWidget $notice;

    /** La barre du bas : le mode, puis les touches. */
    private readonly TextWidget $footer;

    /** Le champ de message, quand c'est lui qui est à l'écran : la complétion par Tab y écrit. */
    private ?InputWidget $input = null;

    private readonly Keybindings $keys;

    /** Ce que l'humain a devant lui ; ne change pas tant que la conversation attend la même chose. */
    private string $interactionKey = '';

    private string $lastRendered = '';

    private AgentMode $mode = AgentMode::Standard;

    /** Le temps de la danse où en est la banane. */
    private int $beat = 0;

    private ?Transcript $transcript = null;

    /** @var list<string> ce que l'humain a déjà envoyé, messages et commandes, du plus ancien au plus récent */
    private array $history = [];

    /** Où en est le rappel par ↑/↓ ; `null` = on tape une ligne neuve. */
    private ?int $recall = null;

    /** La ligne en cours de frappe, mise de côté le temps de parcourir l'historique. */
    private string $draft = '';

    public function __construct(
        private readonly Conversations $conversations,
        private readonly InProcessWorker $worker,
        private readonly SlashCommands $commands,
        public private(set) string $conversation,
        ?TerminalInterface $terminal = null,
    ) {
        $this->tui = new Tui(terminal: $terminal);
        $this->header = new TextWidget();
        $this->thread = new ThreadWidget();
        $this->interaction = new ContainerWidget();
        $this->notice = new TextWidget();
        $this->footer = new TextWidget($this->footerText());
        $this->tui
            ->add($this->header)
            ->add($this->thread)
            ->add($this->notice)
            ->add($this->interaction)
            ->add($this->footer);

        $this->keys = new Keybindings([
            'quit' => ['ctrl+c'],
            'mode' => ['shift+tab'],
            'close' => ['ctrl+x'],
            'complete' => ['tab'],
            'history_previous' => ['up'],
            'history_next' => ['down'],
            'page_up' => ['page_up'],
            'page_down' => ['page_down'],
        ]);
        $this->tui->getEventDispatcher()->addListener(InputEvent::class, $this->onKey(...));
    }

    public function run(): void
    {
        $this->refresh();
        $this->tui->scheduleInterval($this->refresh(...), self::REFRESH_SECONDS);
        $this->tui->scheduleInterval($this->dance(...), self::BEAT_SECONDS);

        $terminal = $this->tui->getTerminal();
        $terminal->write(self::SCREEN_ON.self::MOUSE_ON);
        try {
            $this->tui->run();
        } finally {
            // Sans ça, le terminal resterait en mode souris et sur l'écran alternatif après la
            // sortie : la molette y taperait des séquences, et le shell réapparaîtrait sur un écran
            // sans historique.
            $terminal->write(self::MOUSE_OFF.self::SCREEN_OFF);
        }
    }

    /**
     * Fait avancer le worker, relit le fil, et ne retouche l'écran que si quelque chose a changé.
     */
    public function refresh(): void
    {
        $this->worker->drain();
        $transcript = $this->conversations->transcript($this->conversation);
        $this->mode = $transcript->mode;
        if (null === $this->transcript && [] === $this->history) {
            // Une conversation reprise : ses messages sont l'historique de départ.
            foreach ($transcript->messages as $message) {
                if ($message->isUser() && '' !== trim((string) $message->content)) {
                    $this->history[] = (string) $message->content;
                }
            }
        }
        $this->transcript = $transcript;

        $rendered = json_encode($transcript, \JSON_THROW_ON_ERROR);
        if ($rendered === $this->lastRendered) {
            return;
        }
        $this->lastRendered = $rendered;

        $this->header->setText($this->headerText($transcript));
        $this->footer->setText($this->footerText());
        $this->thread->setText($this->threadText($transcript));
        $this->showInteraction($transcript);
        $this->tui->requestRender();
    }

    /**
     * Un temps de danse : seul l'en-tête change, le fil n'est pas relu.
     */
    public function dance(): void
    {
        ++$this->beat;
        if (null !== $this->transcript) {
            $this->header->setText($this->headerText($this->transcript));
            $this->tui->requestRender();
        }
    }

    private function onKey(InputEvent $event): void
    {
        $data = $event->getData();

        if (1 === preg_match('/^\e\[<(64|65);\d+;\d+[Mm]$/', $data, $wheel)) {
            $event->stopPropagation();
            $this->thread->scroll('64' === $wheel[1] ? self::WHEEL_LINES : -self::WHEEL_LINES);
            $this->tui->requestRender();

            return;
        }

        if (str_starts_with($data, "\e[<") || str_starts_with($data, "\e[M")) {
            // Un clic, pas la molette : rien à en faire, mais il ne doit pas finir dans la saisie.
            $event->stopPropagation();

            return;
        }

        if ($this->keys->matches($data, 'page_up') || $this->keys->matches($data, 'page_down')) {
            $event->stopPropagation();
            $page = max(1, $this->tui->getTerminal()->getRows() - Banana::height() - 6);
            $this->thread->scroll($this->keys->matches($data, 'page_up') ? $page : -$page);
            $this->tui->requestRender();

            return;
        }

        if (null !== $this->input && $this->tui->getFocus() === $this->input
            && ($this->keys->matches($data, 'history_previous') || $this->keys->matches($data, 'history_next'))) {
            $event->stopPropagation();
            $this->recallHistory($this->keys->matches($data, 'history_previous') ? -1 : 1);

            return;
        }

        if ($this->keys->matches($data, 'quit')) {
            $event->stopPropagation();
            $this->tui->stop();
        } elseif ($this->keys->matches($data, 'mode')) {
            $event->stopPropagation();
            $this->act(fn () => $this->conversations->setMode($this->conversation, match ($this->mode) {
                AgentMode::Standard => AgentMode::Edition,
                AgentMode::Edition => AgentMode::Auto,
                AgentMode::Auto => AgentMode::Standard,
            }));
        } elseif ($this->keys->matches($data, 'close')) {
            $event->stopPropagation();
            $this->act(fn () => $this->conversations->close($this->conversation));
        } elseif ($this->keys->matches($data, 'complete') && null !== $this->input && $this->tui->getFocus() === $this->input) {
            // Tab complète le nom de commande tapé — la première qui correspond, comme un shell
            // qui n'aurait qu'une réponse à donner.
            $suggestions = SlashCommands::suggestions($this->input->getValue());
            if ([] !== $suggestions) {
                $event->stopPropagation();
                $this->input->setValue(array_key_first($suggestions).' ');
                $this->showSuggestions($this->input->getValue());
            }
        }
    }

    /**
     * Une intention de l'humain devient un signal ; l'écran se relit aussitôt, sans attendre le
     * prochain tour d'horloge.
     */
    private function act(\Closure $intent): void
    {
        $intent();
        $this->refresh();
    }

    /**
     * L'en-tête : la banane qui danse, et à côté ce qu'il faut savoir de la conversation.
     */
    private function headerText(Transcript $transcript): string
    {
        $status = match (true) {
            null !== $transcript->failure => "\e[31méchouée : ".self::clean($transcript->failure)."\e[0m",
            $transcript->finished => "\e[2mterminée\e[0m",
            $transcript->working => "\e[33mréfléchit…\e[0m",
            default => "\e[32mà toi\e[0m",
        };

        $info = [
            "\e[1;38;2;255;214;64mAgentic\e[0m",
            self::clean($transcript->model),
            $status,
            "\e[2mconversation ".substr($this->conversation, 0, 8)."\e[0m",
        ];
        $offset = intdiv(Banana::height() - \count($info), 2);

        $lines = [];
        foreach (Banana::frame($this->beat) as $row => $banana) {
            $lines[] = '  '.$banana.'   '.($info[$row - $offset] ?? '');
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Le mode en tête de la barre du bas, coloré selon ce qu'il laisse passer sans demander.
     */
    private function footerText(): string
    {
        $color = match ($this->mode) {
            AgentMode::Standard => "\e[32m",
            AgentMode::Edition => "\e[33m",
            AgentMode::Auto => "\e[31m",
        };

        return \sprintf(
            "%s● mode %s\e[0m \e[2m· ⇧Tab mode · ↑↓ historique · / cmd · ^X clore · ^C quitter\e[0m",
            $color,
            $this->mode->value,
        );
    }

    private function threadText(Transcript $transcript): string
    {
        $lines = [];

        foreach ($transcript->messages as $message) {
            $content = self::clean((string) $message->content);
            if ('' === $content) {
                continue;
            }

            if ($message->isUser()) {
                $lines[] = self::styled('› '.$content, "\e[36m");
            } else {
                if (null !== $message->reasoning) {
                    $lines[] = self::styled('⋯ '.self::clean($message->reasoning), "\e[2m");
                }
                $lines[] = $content;
            }
            $lines[] = '';
        }

        foreach ($transcript->steps as $step) {
            $result = null === $step->result ? '…' : self::clean($step->result);
            $lines[] = self::styled(\sprintf('⚙ %s %s → %s', $step->tool, self::clean(json_encode($step->arguments, \JSON_UNESCAPED_UNICODE) ?: ''), $result), "\e[2m");
        }

        return implode("\n", $lines);
    }

    private function showInteraction(Transcript $transcript): void
    {
        [$key, $build] = match (true) {
            $transcript->finished => ['finished', fn (): array => [new TextWidget("Conversation terminée. Ctrl+C pour quitter.\n")]],
            [] !== $transcript->pending => ['approval:'.$transcript->pending[0]->callId, fn (): array => $this->approval($transcript)],
            [] !== $transcript->questions => ['question:'.$transcript->questions[0]->callId, fn (): array => $this->question($transcript)],
            [] !== $transcript->watches => ['watch:'.$transcript->watches[0]->callId, fn (): array => $this->watch($transcript)],
            default => ['input', fn (): array => $this->messageInput()],
        };

        if ($key === $this->interactionKey) {
            return;
        }
        $this->interactionKey = $key;

        $this->interaction->clear();
        $this->input = null;
        $widgets = $build();
        foreach ($widgets as $widget) {
            $this->interaction->add($widget);
        }
        $this->tui->setFocus(end($widgets) ?: null);
    }

    /**
     * @return list<AbstractWidget>
     */
    private function messageInput(): array
    {
        $input = (new InputWidget())->setPrompt('› ');
        $input->onChange(fn (ChangeEvent $event) => $this->showSuggestions($event->getValue()));
        $input->onSubmit(function (SubmitEvent $event) use ($input): void {
            if ($event->isBlank()) {
                return;
            }
            $input->setValue('');
            $this->remember($event->getValue());
            $this->thread->scrollToBottom();

            if (SlashCommands::isCommand($event->getValue())) {
                $this->command($event->getValue());

                return;
            }

            $this->notice->setText('');
            $this->act(fn () => $this->conversations->send($this->conversation, $event->getValue()));
        });
        $this->input = $input;

        return [$input];
    }

    /**
     * Comme un shell : ↑ remonte vers les lignes plus anciennes, ↓ redescend, et au-delà de la plus
     * récente on retrouve ce qu'on était en train de taper.
     */
    private function recallHistory(int $direction): void
    {
        if ([] === $this->history || null === $this->input) {
            return;
        }

        if (null === $this->recall) {
            if ($direction > 0) {
                return;
            }
            $this->draft = $this->input->getValue();
            $this->recall = \count($this->history);
        }

        $this->recall += $direction;
        if ($this->recall >= \count($this->history)) {
            $this->recall = null;
            $this->input->setValue($this->draft);
        } else {
            $this->recall = max(0, $this->recall);
            $this->input->setValue($this->history[$this->recall]);
        }

        $this->showSuggestions($this->input->getValue());
    }

    private function remember(string $line): void
    {
        // Deux fois la même ligne d'affilée n'en fait qu'une, comme dans un shell.
        if ($line !== ($this->history[\count($this->history) - 1] ?? null)) {
            $this->history[] = $line;
        }
        $this->recall = null;
        $this->draft = '';
    }

    private function command(string $line): void
    {
        $outcome = $this->commands->run($this->conversation, $line);
        $this->notice->setText(($outcome->error ? "\e[31m" : "\e[2m").self::clean($outcome->notice)."\e[0m\n");

        if (null !== $outcome->conversation) {
            // Une autre exécution : tout ce que l'écran croyait savoir de la précédente est caduc.
            $this->conversation = $outcome->conversation;
            $this->lastRendered = '';
            $this->interactionKey = '';
        }

        $this->refresh();
    }

    private function showSuggestions(string $value): void
    {
        $suggestions = SlashCommands::suggestions($value);
        $this->notice->setText([] === $suggestions ? '' : "\e[2m".implode("\n", array_map(
            static fn (string $name, string $description): string => \sprintf('%s  %s', $name, $description),
            array_keys($suggestions),
            $suggestions,
        ))."\e[0m\n");
        $this->tui->requestRender();
    }

    /**
     * @return list<AbstractWidget>
     */
    private function approval(Transcript $transcript): array
    {
        $pending = $transcript->pending[0];
        $list = new SelectListWidget([
            ['value' => 'yes', 'label' => 'Approuver'],
            ['value' => 'no', 'label' => 'Refuser'],
        ]);
        $list->onSelect(fn (SelectEvent $event) => $this->act(
            fn () => $this->conversations->decide($this->conversation, $pending->callId, 'yes' === $event->getValue()),
        ));

        return [
            new TextWidget(\sprintf(
                "\e[33m⚠ %s\e[0m %s\n%s",
                self::clean($pending->tool),
                self::clean(json_encode($pending->arguments, \JSON_UNESCAPED_UNICODE) ?: ''),
                self::clean($pending->reason),
            )),
            $list,
        ];
    }

    /**
     * @return list<AbstractWidget>
     */
    private function question(Transcript $transcript): array
    {
        $question = $transcript->questions[0];
        $items = [];
        foreach ($question->options as $option) {
            $items[] = ['value' => self::clean($option->label), 'label' => self::clean($option->label), 'description' => self::clean($option->description)];
        }

        $list = new SelectListWidget($items, multiselect: $question->multiSelect);
        $list->onSelect(fn (SelectEvent $event) => $this->act(
            fn () => $this->conversations->answer($this->conversation, $question->callId, [$event->getValue()]),
        ));
        $list->onMultiSelect(fn (MultiSelectEvent $event) => $this->act(
            fn () => $this->conversations->answer($this->conversation, $question->callId, $event->getValues()),
        ));

        return [
            new TextWidget(\sprintf(
                "\e[35m? %s\e[0m %s%s",
                self::clean($question->header),
                self::clean($question->question),
                $question->multiSelect ? "\n\e[2mEspace coche, Entrée valide\e[0m" : '',
            )),
            $list,
        ];
    }

    /**
     * @return list<AbstractWidget>
     */
    private function watch(Transcript $transcript): array
    {
        $watch = $transcript->watches[0];
        // En production, l'alerte vient du dehors — un webhook, une supervision. Ici l'humain peut
        // la lever à la main, comme la page de la maquette.
        $input = (new InputWidget())->setPrompt('alerte › ');
        $input->onSubmit(fn (SubmitEvent $event) => $this->act(
            fn () => $this->conversations->alert($this->conversation, $watch->callId, $event->getValue()),
        ));

        return [
            new TextWidget(\sprintf(
                "\e[34m◷ en veille sur %s\e[0m : %s\n\e[2mCe que l'agent fera : %s\e[0m",
                $watch->subject->value,
                self::clean($watch->observation),
                self::clean($watch->intention),
            )),
            $input,
        ];
    }

    /**
     * Tout ce qui vient du modèle ou du journal est non sûr : pas de séquence de contrôle à
     * l'écran.
     */
    private static function clean(string $text): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8($text));
    }

    /**
     * Le style s'applique ligne par ligne : le repli à la largeur du terminal ne doit pas le perdre.
     */
    private static function styled(string $text, string $style): string
    {
        return implode("\n", array_map(static fn (string $line): string => $style.$line."\e[0m", explode("\n", $text)));
    }
}
