<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Application\Chat\Transcript;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Revolt\EventLoop;
use Symfony\Component\Tui\Event\CancelEvent;
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
 * The chat screen: the thread, then what the conversation expects from the human — a message, an
 * approval, an answer, an alert.
 *
 * The screen has no conversation state of its own: it reads the projection of the journal again on
 * every refresh, after having moved the worker forward. Quitting (Ctrl+C) does not close the
 * conversation; Ctrl+X closes it; Shift+Tab rotates the mode, shown at the bottom. The wheel and
 * Pg.Up/Pg.Dn scroll the thread; ↑/↓ recall the messages already sent, like a shell. A line that starts with `/` is a command
 * ({@see SlashCommands}), not a message.
 */
final class ChatView
{
    private const REFRESH_SECONDS = 0.25;

    /** The tempo of the banana. */
    private const BEAT_SECONDS = 0.4;

    /** Lines covered by one notch of the wheel. */
    private const WHEEL_LINES = 3;

    /**
     * The terminal sends the wheel to the application rather than to its own scrolling: mode 1000
     * (clicks and wheel, not the moves), SGR 1006 encoding. Restored on the way out.
     */
    private const MOUSE_ON = "\e[?1000h\e[?1006h";

    private const MOUSE_OFF = "\e[?1006l\e[?1000l";

    /**
     * The alternate screen, the one of vim or less: no scrollback of the terminal's own, the
     * previous screen handed back as it was on the way out. The cursor is brought back to the top
     * left, where the rendering starts from.
     */
    private const SCREEN_ON = "\e[?1049h\e[H";

    private const SCREEN_OFF = "\e[?1049l";

    public readonly Tui $tui;

    private readonly TextWidget $header;

    private readonly ThreadWidget $thread;

    private readonly ContainerWidget $interaction;

    /** What the agent is doing while it works — in banana words. */
    private readonly TextWidget $status;

    /** What a command hands back, or the commands matching what is typed. */
    private readonly TextWidget $notice;

    /** The bottom bar: the mode, then the keys. */
    private readonly TextWidget $footer;

    /** The message field, when it is the one on screen: Tab completion writes into it. */
    private ?InputWidget $input = null;

    private readonly Keybindings $keys;

    /** What the human has in front of them; unchanged as long as the conversation waits for the same thing. */
    private string $interactionKey = '';

    private string $lastRendered = '';

    private AgentMode $mode = AgentMode::Standard;

    /** The beat of the dance the banana is on. */
    private int $beat = 0;

    private ?Transcript $transcript = null;

    /** @var list<string> what the human has already sent, messages and commands, oldest first */
    private array $history = [];

    /** Where the ↑/↓ recall stands; `null` = a fresh line is being typed. */
    private ?int $recall = null;

    /** The line being typed, set aside while the history is browsed. */
    private string $draft = '';

    /**
     * The sent messages the journal does not show yet: displayed at once, marked "sent", so that
     * the human knows they have left.
     *
     * @var list<array{text: string, expected: int}> `expected`: the number of human messages in the thread once this one has arrived
     */
    private array $outbox = [];

    private readonly BananaWords $words;

    /** Since when the agent has been working; `null` when it is the human's turn. */
    private ?float $busySince = null;

    /** An activity call can suspend the worker: no two drains at a time. */
    private bool $draining = false;

    /** @var array{choices: list<array{value: string, label: string, description?: string}>, choose: string}|null what a command asks to choose */
    private ?array $choice = null;

    /** What to put into the next input shown — the message undone by `/rewind`. */
    private ?string $prefill = null;

    public function __construct(
        private readonly Conversations $conversations,
        private readonly InProcessWorker $worker,
        private readonly SlashCommands $commands,
        public private(set) string $conversation,
        ?TerminalInterface $terminal = null,
        ?string $startupNotice = null,
    ) {
        $this->tui = new Tui(terminal: $terminal);
        $this->header = new TextWidget();
        $this->thread = new ThreadWidget();
        $this->interaction = new ContainerWidget();
        $this->status = new TextWidget();
        // What the screen must say straight away — an unavailable sandbox. Written before the
        // alternate screen, it would vanish with it.
        $this->notice = new TextWidget(null === $startupNotice ? '' : self::styled('⚠ '.$startupNotice, "\e[33m")."\n");
        $this->footer = new TextWidget($this->footerText());
        $this->words = new BananaWords();
        $this->tui
            ->add($this->header)
            ->add($this->thread)
            ->add($this->status)
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
        // The worker runs on the loop, **not** in the TUI clock: an activity that suspends its
        // fiber — the model call — would otherwise leave `Tui::tick()` in progress, and the screen
        // would stop redrawing until the answer. Here the dance and the rendering carry on during
        // the call.
        $pump = EventLoop::repeat(self::REFRESH_SECONDS, fn (): null => $this->refresh() ?? null);
        $this->tui->scheduleInterval($this->dance(...), self::BEAT_SECONDS);

        $terminal = $this->tui->getTerminal();
        $terminal->write(self::SCREEN_ON.self::MOUSE_ON);
        try {
            $this->tui->run();
        } finally {
            EventLoop::cancel($pump);
            // Without this, the terminal would stay in mouse mode and on the alternate screen
            // after the exit: the wheel would type sequences there, and the shell would reappear on
            // a screen with no scrollback.
            $terminal->write(self::MOUSE_OFF.self::SCREEN_OFF);
        }
    }

    /**
     * Moves the worker forward, reads the thread again, and only touches the screen if something
     * has changed.
     *
     * @param bool $drain false right after an action of the human: we first show that it has left,
     *                    the worker will handle it at the next clock tick
     */
    public function refresh(bool $drain = true): void
    {
        if ($drain && !$this->draining) {
            $this->draining = true;
            try {
                $this->worker->drain();
            } finally {
                $this->draining = false;
            }
        }
        $transcript = $this->conversations->transcript($this->conversation);
        $this->mode = $transcript->mode;
        if (null === $this->transcript && [] === $this->history) {
            // A resumed conversation: its messages are the starting history.
            foreach ($transcript->messages as $message) {
                if ($message->isUser() && '' !== trim((string) $message->content)) {
                    $this->history[] = (string) $message->content;
                }
            }
        }
        $this->transcript = $transcript;

        // A sent message leaves the outbox as soon as the thread shows it.
        $said = self::userMessages($transcript);
        $this->outbox = null !== $transcript->failure
            // A dead run will never see these messages: leaving them "on the way" would lie.
            ? []
            : array_values(array_filter($this->outbox, static fn (array $sent): bool => $sent['expected'] > $said));
        $this->updateBusy($transcript);

        $rendered = json_encode([$transcript, $this->outbox], \JSON_THROW_ON_ERROR);
        if ($rendered === $this->lastRendered) {
            return;
        }
        $this->lastRendered = $rendered;

        $this->header->setText($this->headerText($transcript));
        $this->footer->setText($this->footerText());
        $this->thread->setEntries($this->threadEntries($transcript));
        $this->showInteraction($transcript);
        $this->tui->requestRender();
    }

    /**
     * One beat of the dance: only the header changes, the thread is not read again.
     */
    public function dance(): void
    {
        ++$this->beat;
        if (null !== $this->transcript) {
            $this->header->setText($this->headerText($this->transcript));
            $this->status->setText($this->statusText());
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
            // A click, not the wheel: nothing to do with it, but it must not end up in the input.
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
                AgentMode::Plan => AgentMode::Standard,
                AgentMode::Standard => AgentMode::Edition,
                AgentMode::Edition => AgentMode::Auto,
                AgentMode::Auto => AgentMode::Plan,
            }));
        } elseif ($this->keys->matches($data, 'close')) {
            $event->stopPropagation();
            $this->act(fn () => $this->conversations->close($this->conversation));
        } elseif ($this->keys->matches($data, 'complete') && null !== $this->input && $this->tui->getFocus() === $this->input) {
            // Tab completes the command name typed — the first one that matches, like a shell
            // that would have a single answer to give.
            $suggestions = SlashCommands::suggestions($this->input->getValue());
            if ([] !== $suggestions) {
                $event->stopPropagation();
                $this->input->setValue(array_key_first($suggestions).' ');
                $this->showSuggestions($this->input->getValue());
            }
        }
    }

    /**
     * An intent of the human becomes a signal; the screen reads itself again at once, without
     * waiting for the next clock tick.
     */
    private function act(\Closure $intent): void
    {
        $intent();
        // First show that it has left, right away; the worker — and the model call, which can take
        // a while — will wait for the next clock tick.
        $this->refresh(drain: false);
        $this->tui->processRender();
    }

    private function updateBusy(Transcript $transcript): void
    {
        $busy = !$transcript->finished && ([] !== $this->outbox || $transcript->working);
        if ($busy && null === $this->busySince) {
            $this->busySince = microtime(true);
            $this->words->shuffle();
        } elseif (!$busy) {
            $this->busySince = null;
        }
        $this->status->setText($this->statusText());
    }

    private function statusText(): string
    {
        return null === $this->busySince ? '' : $this->words->line($this->beat, microtime(true) - $this->busySince);
    }

    private static function userMessages(Transcript $transcript): int
    {
        return \count(array_filter($transcript->messages, static fn ($message): bool => $message->isUser()));
    }

    /**
     * The header: the dancing banana, and next to it what there is to know of the conversation.
     */
    /**
     * What the conversation has cost so far, and what it may. Shown always, because a number
     * nobody sees is a number nobody acts on — and the ceiling is off by default.
     */
    private static function spend(Transcript $transcript): string
    {
        $spent = number_format($transcript->tokensSpent, 0, ',', ' ');

        if (0 === $transcript->tokenBudget) {
            return "\e[2m{$spent} tokens\e[0m";
        }

        $share = $transcript->tokensSpent / $transcript->tokenBudget;
        $colour = match (true) { $share >= 1.0 => "\e[31m", $share >= 0.8 => "\e[33m", default => "\e[2m" };

        return $colour.$spent.'/'.number_format($transcript->tokenBudget, 0, ',', ' ')." tokens\e[0m";
    }

    private function headerText(Transcript $transcript): string
    {
        $status = match (true) {
            null !== $transcript->failure => "\e[31mfailed: ".self::clean($transcript->failure)."\e[0m",
            $transcript->finished => "\e[2mfinished\e[0m",
            $transcript->working => "\e[33mthinking…\e[0m",
            default => "\e[32myour turn\e[0m",
        };

        $info = [
            "\e[1;38;2;255;214;64mAgentic\e[0m",
            self::clean($transcript->model),
            $status,
            "\e[2mconversation ".substr($this->conversation, 0, 8)."\e[0m",
            self::spend($transcript),
        ];
        $offset = intdiv(Banana::height() - \count($info), 2);

        $lines = [];
        foreach (Banana::frame($this->beat) as $row => $banana) {
            $lines[] = '  '.$banana.'   '.($info[$row - $offset] ?? '');
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The mode at the head of the bottom bar, coloured after what it lets through without asking.
     */
    private function footerText(): string
    {
        $color = match ($this->mode) {
            AgentMode::Plan => "\e[34m",
            AgentMode::Standard => "\e[32m",
            AgentMode::Edition => "\e[33m",
            AgentMode::Auto => "\e[31m",
        };

        return \sprintf(
            "%s● mode %s\e[0m \e[2m· ⇧Tab mode · ↑↓ history · / cmd · ^X close · ^C quit\e[0m",
            $color,
            $this->mode->value,
        );
    }

    /**
     * The thread as it shows: the human messages and the machinery as styled text, the model
     * answers as Markdown, then what has just been sent and that the journal does not show yet.
     *
     * @return list<array{string, bool}>
     */
    private function threadEntries(Transcript $transcript): array
    {
        $entries = [];

        foreach ($transcript->messages as $message) {
            $content = self::clean((string) $message->content);
            // A tool result is already in the "⚙" steps: showing it here would double it.
            if ('' === $content || 'tool' === $message->role) {
                continue;
            }

            if ($message->isUser()) {
                $entries[] = [self::styled('› '.$content, "\e[36m"), false];
            } else {
                if (null !== $message->reasoning) {
                    $entries[] = [self::styled('⋯ '.self::clean($message->reasoning), "\e[2m"), false];
                }
                $entries[] = [$content, true];
            }
            $entries[] = ['', false];
        }

        foreach ($transcript->steps as $step) {
            $result = null === $step->result ? '…' : self::clean($step->result);
            $entries[] = [self::styled(\sprintf('⚙ %s %s → %s', $step->tool, self::clean(json_encode($step->arguments, \JSON_UNESCAPED_UNICODE) ?: ''), $result), "\e[2m"), false];
        }

        foreach ($this->outbox as $sent) {
            $entries[] = ['', false];
            $entries[] = [self::styled('› '.self::clean($sent['text']), "\e[36m")."  \e[2m✓ sent\e[0m", false];
        }

        return $entries;
    }

    private function showInteraction(Transcript $transcript): void
    {
        [$key, $build] = match (true) {
            null !== $this->choice => ['choice:'.md5(json_encode($this->choice, \JSON_THROW_ON_ERROR)), fn (): array => $this->choiceList()],
            $transcript->finished => ['finished', fn (): array => [new TextWidget("Conversation finished. Ctrl+C to quit.\n")]],
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
        if (null !== $this->prefill) {
            $input->setValue($this->prefill);
            $this->prefill = null;
        }
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
            $this->outbox[] = ['text' => $event->getValue(), 'expected' => self::userMessages($this->transcript ?? $this->conversations->transcript($this->conversation)) + \count($this->outbox) + 1];
            $this->act(fn () => $this->conversations->send($this->conversation, $event->getValue()));
        });
        $this->input = $input;

        return [$input];
    }

    /**
     * Like a shell: ↑ goes up towards the older lines, ↓ comes back down, and past the most recent
     * one you find again what you were typing.
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
        // The same line twice in a row makes only one, as in a shell.
        if ($line !== ($this->history[\count($this->history) - 1] ?? null)) {
            $this->history[] = $line;
        }
        $this->recall = null;
        $this->draft = '';
    }

    /**
     * What the human has just decided, said at once: the card goes away, the trace stays.
     */
    private function confirm(string $what, string $detail): void
    {
        $this->notice->setText(\sprintf("\e[32m✓ %s\e[0m \e[2m— %s\e[0m\n", $what, self::clean($detail)));
    }

    private function command(string $line): void
    {
        $outcome = $this->commands->run($this->conversation, $line);
        $this->notice->setText(($outcome->error ? "\e[31m" : "\e[2m").self::clean($outcome->notice)."\e[0m\n");

        if ([] !== $outcome->choices && null !== $outcome->choose) {
            $this->choice = ['choices' => $outcome->choices, 'choose' => $outcome->choose];
            $this->interactionKey = '';
            $this->lastRendered = '';
        }

        if (null !== $outcome->conversation) {
            $this->switchTo($outcome->conversation);
        }

        if (null !== $outcome->prefill) {
            $this->prefill = $outcome->prefill;
            $this->interactionKey = '';
            $this->lastRendered = '';
        }

        $this->refresh();
    }

    /**
     * Another run: everything the screen thought it knew of the previous one is void. Only the
     * input history stays — it is the human's, not the conversation's.
     */
    private function switchTo(string $conversation): void
    {
        $this->conversation = $conversation;
        $this->lastRendered = '';
        $this->interactionKey = '';
        $this->outbox = [];
        $this->busySince = null;
        $this->thread->scrollToBottom();
    }

    /**
     * @return list<AbstractWidget>
     */
    private function choiceList(): array
    {
        $choice = $this->choice ?? ['choices' => [], 'choose' => ''];
        $items = array_map(static fn (array $item): array => [
            'value' => $item['value'],
            'label' => self::clean($item['label']),
            'description' => self::clean($item['description'] ?? ''),
        ], $choice['choices']);

        $list = new SelectListWidget($items, maxVisible: 8);
        $list->onSelect(function (SelectEvent $event) use ($choice): void {
            $this->choice = null;
            $this->command($choice['choose'].' '.$event->getValue());
        });
        $list->onCancel(function (CancelEvent $event): void {
            $this->choice = null;
            $this->notice->setText('');
            $this->interactionKey = '';
            $this->lastRendered = '';
            $this->refresh(drain: false);
        });

        return [$list];
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
            ['value' => 'yes', 'label' => 'Approve'],
            ['value' => 'no', 'label' => 'Refuse'],
        ]);
        $list->onSelect(function (SelectEvent $event) use ($pending): void {
            $this->confirm('yes' === $event->getValue() ? 'Approved' : 'Refused', $pending->tool);
            $this->act(fn () => $this->conversations->decide($this->conversation, $pending->callId, 'yes' === $event->getValue()));
        });

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
        $list->onSelect(function (SelectEvent $event) use ($question): void {
            $this->confirm('Answer sent', $event->getValue());
            $this->act(fn () => $this->conversations->answer($this->conversation, $question->callId, [$event->getValue()]));
        });
        $list->onMultiSelect(function (MultiSelectEvent $event) use ($question): void {
            $this->confirm('Answer sent', [] === $event->getValues() ? 'nothing' : implode(', ', $event->getValues()));
            $this->act(fn () => $this->conversations->answer($this->conversation, $question->callId, $event->getValues()));
        });

        return [
            new TextWidget(\sprintf(
                "\e[35m? %s\e[0m %s%s",
                self::clean($question->header),
                self::clean($question->question),
                $question->multiSelect ? "\n\e[2mSpace ticks, Enter confirms\e[0m" : '',
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
        // In production, the alert comes from outside — a webhook, a monitoring system. Here the
        // human can raise it by hand, as on the mock-up page.
        $input = (new InputWidget())->setPrompt('alert › ');
        $input->onSubmit(function (SubmitEvent $event) use ($watch): void {
            $this->confirm('Alert raised', '' === trim($event->getValue()) ? $watch->observation : $event->getValue());
            $this->act(fn () => $this->conversations->alert($this->conversation, $watch->callId, $event->getValue()));
        });

        return [
            new TextWidget(\sprintf(
                "\e[34m◷ watching %s\e[0m: %s\n\e[2mWhat the agent will do: %s\e[0m",
                $watch->subject->value,
                self::clean($watch->observation),
                self::clean($watch->intent),
            )),
            $input,
        ];
    }

    /**
     * Everything that comes from the model or the journal is untrusted: no control sequence on the
     * screen.
     */
    private static function clean(string $text): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8($text));
    }

    /**
     * The style applies line by line: the wrapping to the terminal width must not lose it.
     */
    private static function styled(string $text, string $style): string
    {
        return implode("\n", array_map(static fn (string $line): string => $style.$line."\e[0m", explode("\n", $text)));
    }
}
