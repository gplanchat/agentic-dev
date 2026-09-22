<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The thread of the conversation: it takes all the height the other widgets leave, and shows its
 * end — what has just been said stays right above the input — or, once one scrolls up, the chosen
 * window.
 *
 * The thread is a sequence of entries: text already styled, or Markdown — the model answers —
 * formatted by the Markdown rendering of symfony/tui. Everything is wrapped to the real width at
 * render time: a terminal resize is taken into account without recomputing anything elsewhere.
 */
final class ThreadWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    /** @var list<array{string, bool}> the text, and whether it is Markdown */
    private array $entries = [];

    /** @var array<string, list<string>> Markdown renderings by text and width: the parser costs */
    private array $markdown = [];

    private bool $expanded = true;

    /** Lines hidden below the window: 0 = stuck to the bottom of the thread. */
    private int $offset = 0;

    /** The furthest one can scroll up, as known at the last render. */
    private int $maxOffset = 0;

    /**
     * @param string $text lines already styled (ANSI), separated by newlines
     */
    public function setText(string $text): static
    {
        return $this->setEntries([[$text, false]]);
    }

    /**
     * @param list<array{string, bool}> $entries the text, and `true` if it must be read as Markdown
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
     * @param int $lines positive to go up the thread, negative to come back down
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

        // Scrolled up, the last row carries the hint: to reach the first line of the thread, one
        // can therefore go up one line more than what overflows.
        $this->maxOffset = \count($lines) > $rows ? \count($lines) - $rows + 1 : 0;
        $this->offset = min($this->offset, $this->maxOffset);
        if (0 === $this->offset) {
            return \array_slice($lines, -$rows);
        }

        // Scrolled up: the last row says there is newer below, and how to come back to it.
        $window = \array_slice($lines, \count($lines) - $this->offset - ($rows - 1), $rows - 1);
        $hint = \sprintf("\e[2m▼ %d newer line%s below — wheel or Pg.Dn to come back down\e[0m", $this->offset, $this->offset > 1 ? 's' : '');
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
        // Only the renderings still shown are kept: the cache does not grow with the conversation.
        $this->markdown = $cache;

        return $lines;
    }
}
