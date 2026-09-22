<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Tui;

use Gplanchat\AgenticBundle\Tui\Banana;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class BananaTest extends TestCase
{
    /**
     * Deux images de même taille, sinon l'en-tête sauterait à chaque temps de la danse.
     */
    public function testBothFramesHaveTheSameSizeAndDiffer(): void
    {
        foreach ([0, 1] as $index) {
            $frame = Banana::frame($index);
            self::assertCount(Banana::height(), $frame);
            foreach ($frame as $line) {
                self::assertSame(Banana::width(), AnsiUtils::visibleWidth($line));
            }
        }

        self::assertNotSame(Banana::frame(0), Banana::frame(1));
        self::assertSame(Banana::frame(0), Banana::frame(2), 'La danse boucle.');
    }
}
