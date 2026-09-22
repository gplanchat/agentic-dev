<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

use Gplanchat\Agentic\Domain\Tool\ToolInvocation;

/**
 * Un hook de décision : pour les appels d'un outil — éventuellement sous condition de leurs
 * arguments —, passer, demander ou refuser, quoi qu'en dise le mode.
 *
 * **Pure** : la garde est rejouée à chaque reprise, donc une règle ne lit ni base, ni horloge, ni
 * fichier. Les motifs sont ceux de `fnmatch()` (`*`, `?`, `[...]`), sur le nom de l'outil comme sur
 * la valeur des arguments.
 */
final readonly class ToolRule
{
    /**
     * @param array<string, string> $when argument → motif que sa valeur doit suivre ; tous doivent correspondre
     */
    public function __construct(
        public string $tool,
        public ToolVerdict $verdict,
        public array $when = [],
        public string $reason = '',
    ) {
        if ('' === trim($tool)) {
            throw new \InvalidArgumentException('Une règle doit viser un outil (nom ou motif).');
        }
    }

    public function matches(ToolInvocation $call): bool
    {
        if (!fnmatch($this->tool, $call->name)) {
            return false;
        }

        foreach ($this->when as $argument => $pattern) {
            $value = $call->arguments[$argument] ?? null;
            // Une valeur composée ne se compare pas à un motif : la règle ne s'applique pas.
            if (!\is_scalar($value) || !fnmatch($pattern, (string) $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fabrique de frontière : la charge du workflow arrive du journal, donc en tableaux.
     *
     * @param array{tool?: string, decision?: string, when?: array<string, string>, reason?: string} $wire
     */
    public static function fromWire(array $wire): self
    {
        $verdict = match ($wire['decision'] ?? null) {
            'allow' => ToolVerdict::Allow,
            'ask' => ToolVerdict::Ask,
            'deny' => ToolVerdict::Deny,
            default => throw new \InvalidArgumentException(\sprintf('Décision de règle inconnue : « %s » (allow, ask ou deny).', (string) ($wire['decision'] ?? ''))),
        };

        return new self(
            (string) ($wire['tool'] ?? ''),
            $verdict,
            array_map(strval(...), \is_array($wire['when'] ?? null) ? $wire['when'] : []),
            (string) ($wire['reason'] ?? ''),
        );
    }

    /**
     * @return array{tool: string, decision: string, when: array<string, string>, reason: string}
     */
    public function toWire(): array
    {
        return [
            'tool' => $this->tool,
            'decision' => match ($this->verdict) {
                ToolVerdict::Allow => 'allow',
                ToolVerdict::Ask => 'ask',
                ToolVerdict::Deny => 'deny',
            },
            'when' => $this->when,
            'reason' => $this->reason,
        ];
    }
}
