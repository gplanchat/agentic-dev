<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Question;

use Gplanchat\Agentic\Domain\Question\HumanQuestionDesk;
use Gplanchat\Agentic\Domain\Question\PendingQuestion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HumanQuestionDesk::class)]
#[CoversClass(PendingQuestion::class)]
final class HumanQuestionDeskTest extends TestCase
{
    /**
     * The arguments come from the model: an option without a label is thrown away, not fatal.
     */
    public function testAnOptionWithoutLabelIsDropped(): void
    {
        $question = PendingQuestion::fromArguments('c1', [
            'question' => 'What comes next?',
            'options' => [['label' => 'Carry on'], ['label' => '  '], 'anything at all'],
        ]);

        self::assertCount(1, $question->options);
        self::assertSame('Carry on', $question->options[0]->label);
    }

    public function testTheFirstAnswerWinsAndBlankAnswersAreIgnored(): void
    {
        $desk = new HumanQuestionDesk();
        $desk->ask(PendingQuestion::fromArguments('c1', ['question' => 'Q', 'options' => [['label' => 'A']]]));

        $desk->answer('c1', ['A', ' ']);
        $desk->answer('c1', ['B']);

        self::assertSame(['A'], $desk->answersOf('c1'));
        self::assertSame([], $desk->pending());
    }
}
