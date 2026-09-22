<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Watch;

/**
 * Le vocabulaire fermé des événements que l'application publie. Il part au modèle dans le schéma
 * de {@see WatchTool}, et un sujet hors liste est **refusé visiblement** ({@see Watch::fromArguments()})
 * au lieu de produire une veille morte.
 *
 * Ajouter un sujet, c'est l'ajouter ici côté application et publier l'événement correspondant.
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
     * La charge du workflow arrive du journal, donc en tableaux : sujet → description.
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
