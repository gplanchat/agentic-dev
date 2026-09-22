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
     * Les arguments viennent du modèle : une option sans libellé est jetée, pas fatale.
     */
    public function testAnOptionWithoutLabelIsDropped(): void
    {
        $question = PendingQuestion::fromArguments('c1', [
            'question' => 'Quelle suite ?',
            'options' => [['label' => 'Continuer'], ['label' => '  '], 'n’importe quoi'],
        ]);

        self::assertCount(1, $question->options);
        self::assertSame('Continuer', $question->options[0]->label);
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
