<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Tui;

use Gplanchat\AgenticBundle\Tui\Banana;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class BananaTest extends TestCase
{
    /**
     * Two frames of the same size, otherwise the header would jump at every beat of the dance.
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
        self::assertSame(Banana::frame(0), Banana::frame(2), 'The dance loops.');
    }
}
