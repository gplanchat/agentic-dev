<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\ModeToolGuard;
use Gplanchat\Agentic\Domain\Guard\RuleBasedToolGuard;
use Gplanchat\Agentic\Domain\Guard\ToolRule;
use Gplanchat\Agentic\Domain\Mikado\MikadoTool;
use Gplanchat\Agentic\Domain\Question\AskUserQuestion;
use Gplanchat\Agentic\Domain\Team\DelegateTool;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\Agentic\Domain\Tool\ToolInvocation;
use Gplanchat\Agentic\Domain\Watch\WatchTool;
use Gplanchat\AgenticBundle\Mcp\McpCatalog;

/**
 * The chat commands, typed after a `/` instead of a message. They never go to the model: each one
 * becomes a signal or a reading of the thread.
 */
final readonly class SlashCommands
{
    /** @var array<string, string> command → what it does */
    public const COMMANDS = [
        '/help' => 'lists the commands',
        '/mode' => 'shows or changes the mode: /mode standard|edition|auto',
        '/model' => 'shows or changes the model: /model <name>',
        '/tools' => 'lists the tools and what the guard makes of them in the current mode',
        '/clear' => 'closes the conversation and opens a fresh one',
        '/rewind' => 'goes back before one of your messages: /rewind [no.]',
        '/compact' => 'restarts from a summary of the conversation, to lighten the context',
        '/resume' => 'resumes a past conversation: /resume [identifier]',
        '/mcp' => 'lists the MCP servers and the tools they offer',
        '/agents' => 'lists the sub-agents delegate can hand a mission to: /agents [name]',
    ];

    public function __construct(
        private Conversations $conversations,
        private ?McpCatalog $mcp = null,
    ) {
    }

    public static function isCommand(string $line): bool
    {
        return str_starts_with(ltrim($line), '/');
    }

    /**
     * The commands whose name starts with what is typed — as long as only the name is typed.
     *
     * @return array<string, string>
     */
    public static function suggestions(string $line): array
    {
        $line = ltrim($line);
        if (!self::isCommand($line) || str_contains($line, ' ')) {
            return [];
        }

        return array_filter(self::COMMANDS, static fn (string $name): bool => str_starts_with($name, $line), \ARRAY_FILTER_USE_KEY);
    }

    public function run(string $conversation, string $line): SlashOutcome
    {
        $words = preg_split('/\s+/', trim($line)) ?: [];
        $name = strtolower((string) array_shift($words));
        $argument = $words[0] ?? null;

        return match ($name) {
            '/help' => new SlashOutcome($this->help()),
            '/mode' => $this->mode($conversation, $argument),
            '/model' => $this->model($conversation, $argument),
            '/tools' => new SlashOutcome($this->tools($conversation)),
            '/clear' => $this->clear($conversation),
            '/rewind' => $this->rewind($conversation, $argument),
            '/compact' => $this->compact($conversation),
            '/resume' => $this->resume($conversation, $argument),
            '/mcp' => new SlashOutcome($this->mcp($conversation), error: null === $this->mcp),
            '/agents' => $this->agents($conversation, $argument),
            default => new SlashOutcome(\sprintf('Unknown command: %s. /help for the list.', $name), error: true),
        };
    }

    private function help(): string
    {
        $width = max(array_map(mb_strlen(...), array_keys(self::COMMANDS)));

        return implode("\n", array_map(
            static fn (string $name, string $description): string => \sprintf('%s  %s', str_pad($name, $width), $description),
            array_keys(self::COMMANDS),
            self::COMMANDS,
        ));
    }

    private function mode(string $conversation, ?string $argument): SlashOutcome
    {
        $current = $this->conversations->transcript($conversation)->mode;
        if (null === $argument) {
            return new SlashOutcome(\sprintf(
                'Mode: %s. Possible: %s.',
                $current->value,
                implode(', ', array_map(static fn (AgentMode $mode): string => $mode->value, AgentMode::cases())),
            ));
        }

        $mode = AgentMode::tryFrom(strtolower($argument));
        if (null === $mode) {
            return new SlashOutcome(\sprintf('Unknown mode: "%s".', $argument), error: true);
        }

        $this->conversations->setMode($conversation, $mode);

        return new SlashOutcome(\sprintf('Mode: %s.', $mode->value));
    }

    private function model(string $conversation, ?string $argument): SlashOutcome
    {
        if (null === $argument) {
            return new SlashOutcome(\sprintf(
                "Model: %s.\nAvailable: %s.",
                $this->conversations->transcript($conversation)->model,
                implode(', ', $this->conversations->models()),
            ));
        }

        try {
            $this->conversations->setModel($conversation, $argument);
        } catch (\InvalidArgumentException $refused) {
            return new SlashOutcome($refused->getMessage().' /model for the list.', error: true);
        }

        return new SlashOutcome(\sprintf('Model: %s, from the next message on.', $argument));
    }

    private function tools(string $conversation): string
    {
        $transcript = $this->conversations->transcript($conversation);
        // The same guard as the agent's: the project rules, then the mode.
        $guard = new RuleBasedToolGuard($transcript->rules, new ModeToolGuard($transcript->tools));
        $width = max([0, ...array_map(static fn (ToolDefinition $tool): int => mb_strlen($tool->name), $transcript->tools->definitions)]);

        $lines = [];
        foreach ($transcript->tools as $tool) {
            $decision = $guard->decide(new ToolInvocation('preview', $tool->name), $transcript->mode);
            $lines[] = \sprintf(
                '%s  %-8s  %s',
                str_pad($tool->name, $width),
                $tool->effect->value,
                match (true) {
                    $decision->isDenied() => 'denied',
                    $decision->needsApproval() => 'needs approval',
                    default => 'passes',
                },
            );
        }

        $rules = array_map(static fn (ToolRule $rule): string => \sprintf(
            '  %-5s %s%s%s%s%s%s',
            $rule->toWire()['decision'],
            $rule->tool,
            [] === $rule->modes ? '' : ' in '.implode('|', array_map(static fn (AgentMode $mode): string => $mode->value, $rule->modes)),
            implode('', array_map(static fn (string $argument, string $pattern): string => \sprintf(' %s=%s', $argument, $pattern), array_keys($rule->when), $rule->when)),
            implode('', array_map(static fn (string $argument, array $patterns): string => \sprintf(' unless %s=%s', $argument, implode(' | ', $patterns)), array_keys($rule->unless), $rule->unless)),
            // An exemption nobody can see is a guard nobody can audit.
            [] === $rule->unlessRoles ? '' : ' unless role '.implode(' | ', $rule->unlessRoles),
            '' === $rule->reason ? '' : ' — '.$rule->reason,
        ), $transcript->rules);

        return implode("\n", [
            ...([] === $lines ? ['No tool declared by the application.'] : $lines),
            '',
            \sprintf('Mode %s. Always offered: %s.', $transcript->mode->value, implode(', ', [AskUserQuestion::TOOL, WatchTool::TOOL, DelegateTool::TOOL, ...array_map(static fn (ToolDefinition $tool): string => $tool->name, MikadoTool::definitions())])),
            ...(null === $transcript->workspace ? [] : [\sprintf('Workspace: %s%s', $transcript->workspace, is_dir($transcript->workspace) ? '' : ' (created by the first command)')]),
            ...([] === $rules ? [] : ['Project rules (deny > ask > allow, before the mode):', ...$rules]),
        ]);
    }

    private function rewind(string $conversation, ?string $argument): SlashOutcome
    {
        $said = $this->conversations->transcript($conversation)->userMessages();
        if ([] === $said) {
            return new SlashOutcome('Nothing to undo: you have not said anything yet.', error: true);
        }

        if (null === $argument) {
            $choices = [];
            foreach (array_reverse($said, true) as $index => $text) {
                // Short label, text as the description: the list gives the label a narrow column.
                $choices[] = ['value' => (string) ($index + 1), 'label' => \sprintf('#%d', $index + 1), 'description' => self::excerpt($text)];
            }

            return new SlashOutcome('Go back before which message? Enter chooses, Esc cancels.', choices: $choices, choose: '/rewind');
        }

        $number = filter_var($argument, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => \count($said)]]);
        if (false === $number) {
            return new SlashOutcome(\sprintf('A message number between 1 and %d is needed.', \count($said)), error: true);
        }

        $restarted = $this->conversations->restart($conversation, keepUserMessages: $number - 1);

        return new SlashOutcome(
            \sprintf('Back before "%s", in conversation %s. The message is in the input, to take up again or to change.', self::excerpt($said[$number - 1]), substr($restarted, 0, 8)),
            conversation: $restarted,
            prefill: $said[$number - 1],
        );
    }

    private function compact(string $conversation): SlashOutcome
    {
        if ([] === $this->conversations->transcript($conversation)->userMessages()) {
            return new SlashOutcome('Nothing to compact: the conversation is empty.', error: true);
        }

        $restarted = $this->conversations->restart($conversation, compact: true);

        return new SlashOutcome(\sprintf('The conversation restarts from a summary, in %s.', substr($restarted, 0, 8)), conversation: $restarted);
    }

    private function resume(string $conversation, ?string $argument): SlashOutcome
    {
        $others = array_values(array_filter(
            $this->conversations->recent(),
            static fn ($summary): bool => $summary->id !== $conversation,
        ));

        if (null === $argument) {
            if ([] === $others) {
                return new SlashOutcome('No other conversation to resume.', error: true);
            }

            return new SlashOutcome('Resume which conversation? Enter chooses, Esc cancels.', choices: array_map(static fn ($summary): array => [
                'value' => $summary->id,
                'label' => $summary->startedAt?->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d/m H:i') ?? '--/-- --:--',
                'description' => \sprintf('%s  (%s)', self::excerpt($summary->title), $summary->finished ? 'finished' : 'running'),
            ], $others), choose: '/resume');
        }

        $found = array_values(array_filter($others, static fn ($summary): bool => str_starts_with($summary->id, $argument)));
        if (1 !== \count($found)) {
            return new SlashOutcome(\sprintf([] === $found ? 'No conversation starts with "%s".' : 'Several conversations start with "%s": be more precise.', $argument), error: true);
        }

        $summary = $found[0];
        if (!$summary->finished) {
            return new SlashOutcome(\sprintf('Resuming "%s".', self::excerpt($summary->title)), conversation: $summary->id);
        }

        // A finished run is not reopened: a fresh one starts again from its thread.
        $restarted = $this->conversations->restart($summary->id);

        return new SlashOutcome(\sprintf('"%s" was finished: it resumes in %s.', self::excerpt($summary->title), substr($restarted, 0, 8)), conversation: $restarted);
    }

    /**
     * The sub-agents of this conversation, frozen at its start like its tools. With a name, what
     * that one is: its instructions in full, since that is what one wants to check.
     */
    private function agents(string $conversation, ?string $argument): SlashOutcome
    {
        $transcript = $this->conversations->transcript($conversation);
        $profiles = $transcript->profiles;

        if (0 === \count($profiles)) {
            return new SlashOutcome('No sub-agent is declared (agentic.agents). `delegate` still works, with no tools and your model.', error: true);
        }

        if (null !== $argument) {
            $profile = $profiles->find($argument);
            if (null === $profile) {
                return new SlashOutcome(\sprintf('No sub-agent is named "%s". Declared: %s.', $argument, implode(', ', $profiles->names())), error: true);
            }

            return new SlashOutcome(implode("\n", [
                \sprintf('%s — %s', $profile->name, '' === $profile->description ? 'no description' : $profile->description),
                \sprintf('model    %s', $profile->model ?? '(the caller\'s)'),
                \sprintf('ceiling  %s, so at most %s here (the strictest of it and your mode)', $profile->ceiling->value, AgentMode::strictest($profile->ceiling, $transcript->mode)->value),
                \sprintf('tools    %s', [] === $profile->tools ? 'none' : implode(', ', $profile->tools)),
                \sprintf('turns    %d', $profile->maxTurns),
                '',
                '' === $profile->prompt ? '(no instructions of its own)' : $profile->prompt,
            ]));
        }

        $width = max(array_map(mb_strlen(...), $profiles->names()));
        $lines = [];
        foreach ($profiles as $profile) {
            $lines[] = \sprintf(
                '%s  %-8s  %-8s  %s',
                str_pad($profile->name, $width),
                $profile->model ?? 'caller',
                AgentMode::strictest($profile->ceiling, $transcript->mode)->value,
                [] === $profile->tools ? 'no tool' : implode(', ', $profile->tools),
            );
        }

        return new SlashOutcome(implode("\n", [
            ...$lines,
            '',
            'The ceiling shown is what applies here: the strictest of the profile and your mode. /agents <name> shows its instructions.',
        ]));
    }

    /**
     * The MCP servers as they are right now — reached or not — and the tools this conversation
     * froze at its start. The two can differ: the journal wins over what a server offers today.
     */
    private function mcp(string $conversation): string
    {
        if (null === $this->mcp) {
            return 'No MCP server is configured (agentic.mcp.servers).';
        }

        $frozen = $this->conversations->transcript($conversation)->tools;
        // The column is as wide as the longest name shown: MCP tool names run long
        // (`add_comment_to_pending_review`), and a fixed width would shift the effects of a whole
        // server.
        $width = max([0, ...array_map(
            static fn (ToolDefinition $tool): int => mb_strlen($tool->name) - mb_strlen('mcp____'),
            $frozen->definitions,
        )]);
        $lines = [];
        foreach ($this->mcp->status() as $server) {
            $lines[] = \sprintf(
                '%s  %s  %s',
                $server['connected'] ? '●' : '○',
                str_pad($server['server'].' ('.$server['transport'].')', 22),
                $server['connected'] ? \sprintf('%d tool%s', $server['tools'], 1 === $server['tools'] ? '' : 's') : 'unreachable: '.$server['error'],
            );

            foreach ($frozen as $tool) {
                if (str_starts_with($tool->name, 'mcp__'.$server['server'].'__')) {
                    $short = substr($tool->name, \strlen('mcp__'.$server['server'].'__'));
                    $lines[] = \sprintf('    %s  %s', str_pad($short, max(12, $width - mb_strlen($server['server']))), $tool->effect->value);
                }
            }
        }

        if ([] === $lines) {
            return 'No MCP server is configured (agentic.mcp.servers).';
        }

        return implode("\n", [...$lines, '', 'This conversation uses the tools it froze when it started; /tools shows what the guard makes of them.']);
    }

    private static function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > 50 ? mb_substr($text, 0, 49).'…' : $text;
    }

    private function clear(string $conversation): SlashOutcome
    {
        $this->conversations->close($conversation);

        return new SlashOutcome('New conversation.', conversation: $this->conversations->start());
    }
}
