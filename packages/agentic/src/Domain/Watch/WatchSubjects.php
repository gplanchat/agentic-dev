<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Watch;

/**
 * The closed vocabulary of the events the application publishes. It goes out to the model in the
 * schema of {@see WatchTool}, and a subject off the list is **visibly refused**
 * ({@see Watch::fromArguments()}) instead of producing a dead watch.
 *
 * Adding a subject means adding it here on the application side and publishing the matching event.
 *
 * @implements \IteratorAggregate<int, WatchSubject>
 */
final readonly class WatchSubjects implements \Countable, \IteratorAggregate
{
    /** @var array<string, WatchSubject> */
    private array $subjects;

    public function __construct(WatchSubject ...$subjects)
    {
        $indexed = [];
        foreach ($subjects as $subject) {
            $indexed[$subject->value] = $subject;
        }
        $this->subjects = $indexed;
    }

    /**
     * The workflow payload arrives from the journal, hence as arrays: subject → description.
     *
     * @param array<string, string> $wire
     */
    public static function fromWire(array $wire): self
    {
        $subjects = [];
        foreach ($wire as $value => $description) {
            $subjects[] = new WatchSubject((string) $value, (string) $description);
        }

        return new self(...$subjects);
    }

    /**
     * @return array<string, string>
     */
    public function toWire(): array
    {
        return array_map(static fn (WatchSubject $subject): string => $subject->description, $this->subjects);
    }

    public function find(string $value): ?WatchSubject
    {
        return $this->subjects[$value] ?? null;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_keys($this->subjects);
    }

    public function count(): int
    {
        return \count($this->subjects);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator(array_values($this->subjects));
    }
}
