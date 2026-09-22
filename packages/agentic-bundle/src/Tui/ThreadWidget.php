<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * Le fil de la conversation : il prend toute la hauteur que les autres widgets laissent, et en
 * montre la fin — ce qui vient d'être dit reste juste au-dessus de la saisie — ou, une fois qu'on
 * remonte, la fenêtre choisie.
 *
 * Le fil est une suite d'entrées : du texte déjà stylé, ou du Markdown — les réponses du modèle —
 * mis en forme par le rendu Markdown de symfony/tui. Tout est replié à la largeur réelle au moment du
 * rendu : un redimensionnement du terminal est pris en compte sans rien recalculer ailleurs.
 */
final class ThreadWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    /** @var list<array{string, bool}> texte, et s'il est en Markdown */
    private array $entries = [];

    /** @var array<string, list<string>> rendus Markdown par texte et largeur : le parseur coûte */
    private array $markdown = [];

    private bool $expanded = true;

    /** Lignes cachées sous la fenêtre : 0 = collé au bas du fil. */
    private int $offset = 0;

    /** Le plus loin qu'on puisse remonter, connu au dernier rendu. */
    private int $maxOffset = 0;

    /**
     * @param string $text lignes déjà stylées (ANSI), séparées par des retours à la ligne
     */
    public function setText(string $text): static
    {
        return $this->setEntries([[$text, false]]);
    }

    /**
     * @param list<array{string, bool}> $entries texte, et `true` s'il faut le lire comme du Markdown
     */
    public function setEntries(array $entries): static
    {
        if ($entries !== $this->entries) {
            $this->entries = $entries;
            $this->invalidate();
        }

        return $this;
    }

    /**
     * @param int $lines positif pour remonter dans le fil, négatif pour redescendre
     */
    public function scroll(int $lines): static
    {
        $offset = max(0, min($this->maxOffset, $this->offset + $lines));
        if ($offset !== $this->offset) {
            $this->offset = $offset;
            $this->invalidate();
        }

        return $this;
    }

    public function scrollToBottom(): static
    {
        return $this->scroll(-$this->offset);
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function expandVertically(bool $expand): static
    {
        if ($expand !== $this->expanded) {
            $this->expanded = $expand;
            $this->invalidate();
        }

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->expanded;
    }

    public function render(RenderContext $context): array
    {
        $columns = max(1, $context->getColumns());
        $rows = max(1, $context->getRows());
        $lines = $this->lines($columns);

        // Remonté, la dernière rangée sert à l'indication : pour atteindre la première ligne du fil,
        // on peut donc remonter d'une ligne de plus que ce qui dépasse.
        $this->maxOffset = \count($lines) > $rows ? \count($lines) - $rows + 1 : 0;
        $this->offset = min($this->offset, $this->maxOffset);
        if (0 === $this->offset) {
            return \array_slice($lines, -$rows);
        }

        // Remonté : la dernière rangée dit qu'il y a plus récent en dessous, et comment y revenir.
        $window = \array_slice($lines, \count($lines) - $this->offset - ($rows - 1), $rows - 1);
        $hint = \sprintf("\e[2m▼ %d ligne%s plus récente%s — molette ou Pg.Suiv pour redescendre\e[0m", $this->offset, $this->offset > 1 ? 's' : '', $this->offset > 1 ? 's' : '');
        $window[] = TextWrapper::wrapTextWithAnsi($hint, $columns)[0];

        return $window;
    }

    /**
     * @return list<string>
     */
    private function lines(int $columns): array
    {
        $lines = [];
        $cache = [];
        foreach ($this->entries as [$text, $isMarkdown]) {
            if (!$isMarkdown) {
                array_push($lines, ...TextWrapper::wrapTextWithAnsi($text, $columns));

                continue;
            }

            $key = $columns.':'.$text;
            $cache[$key] = $this->markdown[$key] ?? (new MarkdownWidget($text))->render(new RenderContext($columns, \PHP_INT_MAX));
            array_push($lines, ...$cache[$key]);
        }
        // Seuls les rendus encore affichés restent : le cache ne grossit pas avec la conversation.
        $this->markdown = $cache;

        return $lines;
    }
}
