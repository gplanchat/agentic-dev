<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Team;

use Gplanchat\Agentic\Domain\Guard\AgentMode;

/**
 * A sub-agent the application declares: what it is for, which model carries it, what it is allowed
 * to do, and how much authority it may hold.
 *
 * **A profile never grants authority.** Its `ceiling` narrows what its delegate may do; the delegate
 * still takes the strictest of that and of its parent's effective mode ({@see AgentMode::strictest()}).
 * A profile in `auto` handed to a parent in `standard` stays `standard` — otherwise naming a
 * sub-agent would be the way around the guard, which is exactly what delegation must not become.
 */
final readonly class AgentProfile
{
    /**
     * @param list<string> $tools patterns (fnmatch) of the tools the sub-agent may use; empty = none
     * @param list<string> $roles what the sub-agent may still claim of its caller's identity; it is
     *                            an intersection, never a grant ({@see \Gplanchat\Agentic\Domain\Identity\Principal::restrictedTo()}),
     *                            and empty hands it an identity that claims nothing
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $prompt,
        public ?string $model = null,
        public AgentMode $ceiling = AgentMode::Standard,
        public array $tools = [],
        public int $maxTurns = 1,
        public array $roles = [],
    ) {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('An agent profile must carry a name.');
        }
    }

    public function allows(string $tool): bool
    {
        foreach ($this->tools as $pattern) {
            if (fnmatch($pattern, $tool)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Boundary factory: the workflow payload comes from the journal, so in arrays.
     *
     * @param array{description?: string, prompt?: string, model?: string|null, ceiling?: string, tools?: list<string>, max_turns?: int, roles?: array<mixed>} $wire
     */
    public static function fromWire(string $name, array $wire): self
    {
        return new self(
            $name,
            (string) ($wire['description'] ?? ''),
            (string) ($wire['prompt'] ?? ''),
            '' === trim((string) ($wire['model'] ?? '')) ? null : (string) $wire['model'],
            AgentMode::tryFrom((string) ($wire['ceiling'] ?? '')) ?? AgentMode::Standard,
            array_values(array_map(strval(...), $wire['tools'] ?? [])),
            max(1, (int) ($wire['max_turns'] ?? 1)),
            array_values(array_map(strval(...), (array) ($wire['roles'] ?? []))),
        );
    }

    /**
     * @return array{description: string, prompt: string, model: string|null, ceiling: string, tools: list<string>, max_turns: int, roles?: list<string>}
     */
    public function toWire(): array
    {
        return array_filter([
            'description' => $this->description,
            'prompt' => $this->prompt,
            'model' => $this->model,
            'ceiling' => $this->ceiling->value,
            'tools' => $this->tools,
            'max_turns' => $this->maxTurns,
            // Only if it says something: a profile from before keeps the shape it had in the journal.
            'roles' => $this->roles,
        ], static fn (mixed $value, string $key): bool => 'roles' !== $key || [] !== $value, \ARRAY_FILTER_USE_BOTH);
    }
}
