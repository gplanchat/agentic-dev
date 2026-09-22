<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Team;

/**
 * The sub-agents an application offers, a closed vocabulary like the watch subjects: the list goes
 * to the model inside the schema of {@see DelegateTool}, and a name outside it is refused visibly
 * rather than silently turned into an anonymous delegation.
 *
 * @implements \IteratorAggregate<int, AgentProfile>
 */
final readonly class AgentProfiles implements \Countable, \IteratorAggregate
{
    /** @var array<string, AgentProfile> */
    private array $profiles;

    public function __construct(AgentProfile ...$profiles)
    {
        $indexed = [];
        foreach ($profiles as $profile) {
            $indexed[$profile->name] = $profile;
        }
        $this->profiles = $indexed;
    }

    /**
     * @param array<string, array<string, mixed>> $wire
     */
    public static function fromWire(array $wire): self
    {
        $profiles = [];
        foreach ($wire as $name => $profile) {
            $profiles[] = AgentProfile::fromWire((string) $name, \is_array($profile) ? $profile : []);
        }

        return new self(...$profiles);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toWire(): array
    {
        return array_map(static fn (AgentProfile $profile): array => $profile->toWire(), $this->profiles);
    }

    public function find(string $name): ?AgentProfile
    {
        return $this->profiles[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->profiles);
    }

    public function count(): int
    {
        return \count($this->profiles);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator(array_values($this->profiles));
    }
}
