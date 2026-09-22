<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * Le fil de la conversation : il prend toute la hauteur que les autres widgets laissent, et en
 * montre la fin — ce qui vient d'être dit reste juste au-dessus de la saisie — ou, une fois qu'on
 * remonte, la fenêtre choisie.
 *
 * Le texte est replié à la largeur réelle au moment du rendu, et le fil est coupé à la hauteur
 * réelle : un redimensionnement du terminal est pris en compte sans rien recalculer ailleurs.
 */
final class ThreadWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    private string $text = '';

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
        if ($text !== $this->text) {
            $this->text = $text;
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
        $lines = TextWrapper::wrapTextWithAnsi($this->text, max(1, $context->getColumns()));
        $rows = max(1, $context->getRows());

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
        $window[] = TextWrapper::wrapTextWithAnsi($hint, max(1, $context->getColumns()))[0];

        return $window;
    }
}
