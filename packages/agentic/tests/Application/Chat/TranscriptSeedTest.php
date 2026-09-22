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
        $transcript = $this->transcript('un', 'réponse un', 'deux', 'réponse deux', 'trois', 'réponse trois');

        self::assertSame(['un', 'deux', 'trois'], $transcript->userMessages());
        self::assertSame(
            [['role' => 'user', 'content' => 'un'], ['role' => 'assistant', 'content' => 'réponse un']],
            $transcript->seedBefore(1),
        );
        self::assertSame([], $transcript->seedBefore(0));
        self::assertCount(6, $transcript->seedBefore(99), 'Au-delà du dernier message, tout le fil.');
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
