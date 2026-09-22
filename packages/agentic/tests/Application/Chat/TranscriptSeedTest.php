<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Application\Chat;

use Gplanchat\Agentic\Application\Chat\Transcript;
use Gplanchat\Agentic\Application\Chat\TranscriptMessage;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use PHPUnit\Framework\TestCase;

final class TranscriptSeedTest extends TestCase
{
    public function testTheSeedIsCutJustBeforeTheChosenUserMessage(): void
    {
        $transcript = $this->transcript('one', 'answer one', 'two', 'answer two', 'three', 'answer three');

        self::assertSame(['one', 'two', 'three'], $transcript->userMessages());
        self::assertSame(
            [['role' => 'user', 'content' => 'one'], ['role' => 'assistant', 'content' => 'answer one']],
            $transcript->seedBefore(1),
        );
        self::assertSame([], $transcript->seedBefore(0));
        self::assertCount(6, $transcript->seedBefore(99), 'Past the last message, the whole thread.');
    }

    private function transcript(string ...$texts): Transcript
    {
        $messages = [];
        foreach ($texts as $i => $text) {
            $messages[] = 0 === $i % 2 ? TranscriptMessage::user($text) : TranscriptMessage::assistant($text);
        }

        return new Transcript($messages, [], [], [], [], AgentMode::Standard, null, false, false);
    }
}
