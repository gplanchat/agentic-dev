<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\ModeToolGuard;
use Gplanchat\Agentic\Domain\Guard\RuleBasedToolGuard;
use Gplanchat\Agentic\Domain\Guard\ToolRule;
use Gplanchat\Agentic\Domain\Question\AskUserQuestion;
use Gplanchat\Agentic\Domain\Team\DelegateTool;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\Agentic\Domain\Tool\ToolInvocation;
use Gplanchat\Agentic\Domain\Watch\WatchTool;

/**
 * Les commandes du chat, tapées après un `/` au lieu d'un message. Elles ne partent jamais au
 * modèle : chacune devient un signal ou une lecture du fil.
 */
final readonly class SlashCommands
{
    /** @var array<string, string> commande → ce qu'elle fait */
    public const COMMANDS = [
        '/help' => 'liste les commandes',
        '/mode' => 'affiche ou change le mode : /mode standard|edition|auto',
        '/model' => 'affiche ou change le modèle : /model <nom>',
        '/tools' => 'liste les outils et ce que la garde en fait dans le mode courant',
        '/clear' => 'clôt la conversation et en ouvre une neuve',
        '/rewind' => 'revient avant un de tes messages : /rewind [n°]',
        '/compact' => 'repart d’un résumé de la conversation, pour alléger le contexte',
        '/resume' => 'reprend une conversation passée : /resume [identifiant]',
    ];

    public function __construct(private Conversations $conversations)
    {
    }

    public static function isCommand(string $line): bool
    {
        return str_starts_with(ltrim($line), '/');
    }

    /**
     * Les commandes dont le nom commence par ce qui est tapé — tant que seul le nom est tapé.
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
            default => new SlashOutcome(\sprintf('Commande inconnue : %s. /help pour la liste.', $name), error: true),
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
                'Mode : %s. Possibles : %s.',
                $current->value,
                implode(', ', array_map(static fn (AgentMode $mode): string => $mode->value, AgentMode::cases())),
            ));
        }

        $mode = AgentMode::tryFrom(strtolower($argument));
        if (null === $mode) {
            return new SlashOutcome(\sprintf('Mode inconnu : « %s ».', $argument), error: true);
        }

        $this->conversations->setMode($conversation, $mode);

        return new SlashOutcome(\sprintf('Mode : %s.', $mode->value));
    }

    private function model(string $conversation, ?string $argument): SlashOutcome
    {
        if (null === $argument) {
            return new SlashOutcome(\sprintf(
                "Modèle : %s.\nDisponibles : %s.",
                $this->conversations->transcript($conversation)->model,
                implode(', ', $this->conversations->models()),
            ));
        }

        try {
            $this->conversations->setModel($conversation, $argument);
        } catch (\InvalidArgumentException $refused) {
            return new SlashOutcome($refused->getMessage().' /model pour la liste.', error: true);
        }

        return new SlashOutcome(\sprintf('Modèle : %s, à partir du prochain message.', $argument));
    }

    private function tools(string $conversation): string
    {
        $transcript = $this->conversations->transcript($conversation);
        // La même garde que celle de l'agent : les règles du projet, puis le mode.
        $guard = new RuleBasedToolGuard($transcript->rules, new ModeToolGuard($transcript->tools));
        $width = max([0, ...array_map(static fn (ToolDefinition $tool): int => mb_strlen($tool->name), $transcript->tools->definitions)]);

        $lines = [];
        foreach ($transcript->tools as $tool) {
            $decision = $guard->decide(new ToolInvocation('apercu', $tool->name), $transcript->mode);
            $lines[] = \sprintf(
                '%s  %-8s  %s',
                str_pad($tool->name, $width),
                $tool->effect->value,
                match (true) {
                    $decision->isDenied() => 'refusé',
                    $decision->needsApproval() => 'demande une validation',
                    default => 'passe',
                },
            );
        }

        $rules = array_map(static fn (ToolRule $rule): string => \sprintf(
            '  %-5s %s%s%s',
            $rule->toWire()['decision'],
            $rule->tool,
            implode('', array_map(static fn (string $argument, string $pattern): string => \sprintf(' %s=%s', $argument, $pattern), array_keys($rule->when), $rule->when)),
            '' === $rule->reason ? '' : ' — '.$rule->reason,
        ), $transcript->rules);

        return implode("\n", [
            ...([] === $lines ? ['Aucun outil déclaré par l’application.'] : $lines),
            '',
            \sprintf('Mode %s. Toujours offerts : %s.', $transcript->mode->value, implode(', ', [AskUserQuestion::TOOL, WatchTool::TOOL, DelegateTool::TOOL])),
            ...([] === $rules ? [] : ['Règles du projet (refus > demande > accord, avant le mode) :', ...$rules]),
        ]);
    }

    private function rewind(string $conversation, ?string $argument): SlashOutcome
    {
        $said = $this->conversations->transcript($conversation)->userMessages();
        if ([] === $said) {
            return new SlashOutcome('Rien à défaire : tu n’as encore rien dit.', error: true);
        }

        if (null === $argument) {
            $choices = [];
            foreach (array_reverse($said, true) as $index => $text) {
                // Libellé court, texte en description : la liste réserve au libellé une colonne étroite.
                $choices[] = ['value' => (string) ($index + 1), 'label' => \sprintf('n° %d', $index + 1), 'description' => self::excerpt($text)];
            }

            return new SlashOutcome('Revenir avant quel message ? Entrée choisit, Échap annule.', choices: $choices, choose: '/rewind');
        }

        $number = filter_var($argument, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => \count($said)]]);
        if (false === $number) {
            return new SlashOutcome(\sprintf('Il faut un numéro de message entre 1 et %d.', \count($said)), error: true);
        }

        $restarted = $this->conversations->restart($conversation, keepUserMessages: $number - 1);

        return new SlashOutcome(
            \sprintf('Retour avant « %s », dans la conversation %s. Le message est dans la saisie, à reprendre ou à changer.', self::excerpt($said[$number - 1]), substr($restarted, 0, 8)),
            conversation: $restarted,
            prefill: $said[$number - 1],
        );
    }

    private function compact(string $conversation): SlashOutcome
    {
        if ([] === $this->conversations->transcript($conversation)->userMessages()) {
            return new SlashOutcome('Rien à compacter : la conversation est vide.', error: true);
        }

        $restarted = $this->conversations->restart($conversation, compact: true);

        return new SlashOutcome(\sprintf('La conversation repart d’un résumé, dans %s.', substr($restarted, 0, 8)), conversation: $restarted);
    }

    private function resume(string $conversation, ?string $argument): SlashOutcome
    {
        $others = array_values(array_filter(
            $this->conversations->recent(),
            static fn ($summary): bool => $summary->id !== $conversation,
        ));

        if (null === $argument) {
            if ([] === $others) {
                return new SlashOutcome('Aucune autre conversation à reprendre.', error: true);
            }

            return new SlashOutcome('Reprendre quelle conversation ? Entrée choisit, Échap annule.', choices: array_map(static fn ($summary): array => [
                'value' => $summary->id,
                'label' => $summary->startedAt?->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d/m H:i') ?? '--/-- --:--',
                'description' => \sprintf('%s  (%s)', self::excerpt($summary->title), $summary->finished ? 'terminée' : 'en cours'),
            ], $others), choose: '/resume');
        }

        $found = array_values(array_filter($others, static fn ($summary): bool => str_starts_with($summary->id, $argument)));
        if (1 !== \count($found)) {
            return new SlashOutcome(\sprintf([] === $found ? 'Aucune conversation ne commence par « %s ».' : 'Plusieurs conversations commencent par « %s » : précise.', $argument), error: true);
        }

        $summary = $found[0];
        if (!$summary->finished) {
            return new SlashOutcome(\sprintf('Reprise de « %s ».', self::excerpt($summary->title)), conversation: $summary->id);
        }

        // Une exécution terminée ne se rouvre pas : une neuve repart de son fil.
        $restarted = $this->conversations->restart($summary->id);

        return new SlashOutcome(\sprintf('« %s » était terminée : elle reprend dans %s.', self::excerpt($summary->title), substr($restarted, 0, 8)), conversation: $restarted);
    }

    private static function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > 50 ? mb_substr($text, 0, 49).'…' : $text;
    }

    private function clear(string $conversation): SlashOutcome
    {
        $this->conversations->close($conversation);

        return new SlashOutcome('Nouvelle conversation.', conversation: $this->conversations->start());
    }
}
