<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * Le fil de la conversation : il prend toute la hauteur que les autres widgets laissent, et en
 * montre la fin — ce qui vient d'être dit reste juste au-dessus de la saisie.
 *
 * Le texte est replié à la largeur réelle au moment du rendu, et le fil est coupé à la hauteur
 * réelle : un redimensionnement du terminal est pris en compte sans rien recalculer ailleurs.
 */
final class ThreadWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    private string $text = '';

    private bool $expanded = true;

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

        // ponytail: pas de défilement arrière — la fin du fil qui tient à l'écran. Un vrai
        // défilement quand les conversations le demanderont.
        return \array_slice($lines, -max(1, $context->getRows()));
    }
}
