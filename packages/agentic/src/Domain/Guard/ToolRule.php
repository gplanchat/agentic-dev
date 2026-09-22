<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

use Gplanchat\Agentic\Domain\Tool\ToolInvocation;

/**
 * A decision hook: for the calls of a tool — possibly conditioned on their arguments — go through,
 * ask or refuse, whatever the mode says.
 *
 * `modes` restricts the rule to certain modes (empty: all of them). `unless` sets it aside when an
 * argument follows one of the given patterns — that is what expresses an allow list: "in `auto`,
 * ask, except for these commands". An argument that is absent or compound follows no pattern: the
 * rule applies, and an allow list never opens on a value it cannot read.
 *
 * **Pure**: the guard is replayed on every resume, so a rule reads neither database, nor clock, nor
 * file. The patterns are those of `fnmatch()` (`*`, `?`, `[...]`), on the tool name as well as on
 * the value of the arguments.
 */
final readonly class ToolRule
{
    /**
     * @param array<string, string>       $when   argument → pattern its value must follow; all must match
     * @param list<AgentMode>             $modes  the modes where the rule holds; empty: all of them
     * @param array<string, list<string>> $unless argument → patterns that set the rule aside
     */
    public function __construct(
        public string $tool,
        public ToolVerdict $verdict,
        public array $when = [],
        public string $reason = '',
        public array $modes = [],
        public array $unless = [],
    ) {
        if ('' === trim($tool)) {
            throw new \InvalidArgumentException('A rule must target a tool (name or pattern).');
        }
    }

    public function matches(ToolInvocation $call, ?AgentMode $mode = null): bool
    {
        if (!fnmatch($this->tool, $call->name)) {
            return false;
        }

        if ([] !== $this->modes && !\in_array($mode, $this->modes, true)) {
            return false;
        }

        foreach ($this->unless as $argument => $patterns) {
            $value = $call->arguments[$argument] ?? null;
            if (!\is_scalar($value)) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, (string) $value)) {
                    return false;
                }
            }
        }

        foreach ($this->when as $argument => $pattern) {
            $value = $call->arguments[$argument] ?? null;
            // A compound value does not compare to a pattern: the rule does not apply.
            if (!\is_scalar($value) || !fnmatch($pattern, (string) $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A boundary factory: the workflow payload arrives from the journal, hence as arrays.
     *
     * An unknown mode throws: filtered out silently, it would leave the rule holding in every mode —
     * harmless for a refusal, but an opening for an approval.
     *
     * @param array{tool?: string, decision?: string, when?: array<string, string>, reason?: string, modes?: list<string>, unless?: array<string, list<string>>} $wire
     */
    public static function fromWire(array $wire): self
    {
        $verdict = match ($wire['decision'] ?? null) {
            'allow' => ToolVerdict::Allow,
            'ask' => ToolVerdict::Ask,
            'deny' => ToolVerdict::Deny,
            default => throw new \InvalidArgumentException(\sprintf('Unknown rule decision: "%s" (allow, ask or deny).', (string) ($wire['decision'] ?? ''))),
        };

        return new self(
            (string) ($wire['tool'] ?? ''),
            $verdict,
            array_map(strval(...), \is_array($wire['when'] ?? null) ? $wire['when'] : []),
            (string) ($wire['reason'] ?? ''),
            array_values(array_map(
                static fn (mixed $mode): AgentMode => AgentMode::tryFrom((string) $mode)
                    ?? throw new \InvalidArgumentException(\sprintf('Unknown rule mode: "%s" (auto, edition or standard).', (string) $mode)),
                \is_array($wire['modes'] ?? null) ? $wire['modes'] : [],
            )),
            array_map(
                static fn (mixed $patterns): array => array_values(array_map(strval(...), (array) $patterns)),
                \is_array($wire['unless'] ?? null) ? $wire['unless'] : [],
            ),
        );
    }

    /**
     * `modes` and `unless` only go out if they say something: a rule from before keeps the same
     * shape in the journal.
     *
     * @return array{tool: string, decision: string, when: array<string, string>, reason: string, modes?: list<string>, unless?: array<string, list<string>>}
     */
    public function toWire(): array
    {
        return array_filter([
            'tool' => $this->tool,
            'decision' => match ($this->verdict) {
                ToolVerdict::Allow => 'allow',
                ToolVerdict::Ask => 'ask',
                ToolVerdict::Deny => 'deny',
            },
            'when' => $this->when,
            'reason' => $this->reason,
            'modes' => array_map(static fn (AgentMode $mode): string => $mode->value, $this->modes),
            'unless' => $this->unless,
        ], static fn (mixed $value, string $key): bool => !\in_array($key, ['modes', 'unless'], true) || [] !== $value, \ARRAY_FILTER_USE_BOTH);
    }
}
