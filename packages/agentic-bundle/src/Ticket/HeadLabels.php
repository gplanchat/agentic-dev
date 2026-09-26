<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Ticket;

use Gplanchat\Agentic\Domain\Ticket\HeadKind;

/**
 * The forge label of each head family. The names follow the project's vocabulary (EWA-002 § 1);
 * the families do not change.
 */
final readonly class HeadLabels
{
    /** @var array<string, string> family → label */
    private array $labels;

    /**
     * @param array<string, string> $labels family value → label; a family left out keeps its own name
     *
     * @throws \InvalidArgumentException on an unknown family, an empty label, or one label for two
     *                                   families — a ticket would then read as either
     */
    public function __construct(array $labels = [])
    {
        $all = [];
        foreach (HeadKind::cases() as $kind) {
            $all[$kind->value] = trim($labels[$kind->value] ?? $kind->value);
            if ('' === $all[$kind->value]) {
                throw new \InvalidArgumentException(\sprintf('The label of the head family "%s" is empty.', $kind->value));
            }
        }
        if ([] !== $unknown = array_diff(array_keys($labels), array_keys($all))) {
            throw new \InvalidArgumentException(\sprintf('Unknown head families: %s (%s).', implode(', ', $unknown), implode(', ', array_keys($all))));
        }
        if (\count(array_unique(array_map('strtolower', $all))) !== \count($all)) {
            throw new \InvalidArgumentException('Two head families share a label: a ticket would read as either.');
        }
        $this->labels = $all;
    }

    public function of(HeadKind $kind): string
    {
        return $this->labels[$kind->value];
    }

    /**
     * @param list<string> $labels a ticket's labels
     *
     * @throws \UnexpectedValueException when it carries two families
     */
    public function kindOf(array $labels): ?HeadKind
    {
        $kinds = [];
        foreach ($this->labels as $kind => $label) {
            foreach ($labels as $name) {
                if (0 === strcasecmp($label, $name)) {
                    $kinds[] = HeadKind::from($kind);
                }
            }
        }
        if (\count($kinds) > 1) {
            throw new \UnexpectedValueException(\sprintf('A ticket labelled as two head families: %s.', implode(', ', array_map(fn (HeadKind $kind): string => $this->labels[$kind->value], $kinds))));
        }

        return $kinds[0] ?? null;
    }
}
