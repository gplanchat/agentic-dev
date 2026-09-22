<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Infrastructure\SymfonyAi\ScriptedChatModelClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Model;

#[CoversClass(ScriptedChatModelClient::class)]
final class ScriptedTriggersTest extends TestCase
{
    public static function prompts(): \Generator
    {
        yield 'a city asks for the weather' => ['What is the weather in Paris?', 'weather'];
        yield 'a note is saved' => ['Take a note about the meeting', 'save_note'];
        yield 'an email is sent' => ['Send a mail to the team', 'send_email'];
        yield 'delegation is asked for' => ['Delegate the summary', 'delegate'];
        yield 'a question is asked' => ['Ask me what you need', 'ask_user'];
        // The trap of substrings: "task" contains "ask", "important" contains "import".
        yield 'task does not ask a question' => ['Delegate this task', 'delegate'];
        yield 'important does not trigger the import question' => ['Take an important note', 'save_note'];
    }

    #[DataProvider('prompts')]
    public function testATriggerIsAWholeWord(string $prompt, string $expectedTool): void
    {
        $result = (new ScriptedChatModelClient())->request(new Model('scripted'), ['messages' => [['role' => 'user', 'content' => $prompt]]]);

        self::assertSame($expectedTool, $result->getData()['choices'][0]['message']['tool_calls'][0]['function']['name'] ?? null);
    }
}
