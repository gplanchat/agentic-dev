<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Question;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

/**
 * The tool through which the agent asks the human a question, questionnaire style.
 *
 * It has no activity behind it: its result is not computed, it is *awaited*. It is the only tool
 * whose execution is a suspension.
 *
 * Always offered to the agent — an agent that cannot ask makes things up. Classified `read`:
 * asking breaks nothing, so the guard has no business opposing it, and nobody should have to
 * authorise a question before answering it.
 */
final class AskUserQuestion
{
    public const TOOL = 'ask_user';

    private function __construct()
    {
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            self::TOOL,
            'Asks the user a question and waits for the answer. To be used when a decision belongs '
            .'to them — a choice between several possible continuations, a preference, a piece of '
            .'information that nothing lets you guess. It is not for asking permission to run a '
            .'tool: that happens on its own.',
            ToolEffect::Read,
            [
                'type' => 'object',
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'description' => 'The question, worded to be read as is.',
                    ],
                    'header' => [
                        'type' => 'string',
                        'description' => 'Short label saying what it is about (a few words).',
                    ],
                    'options' => [
                        'type' => 'array',
                        'description' => 'The possible continuations. Two to four, distinct, the recommended one first.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => ['type' => 'string', 'description' => 'The choice, in a few words.'],
                                'description' => ['type' => 'string', 'description' => 'What this choice implies.'],
                            ],
                            'required' => ['label'],
                        ],
                    ],
                    'multiSelect' => [
                        'type' => 'boolean',
                        'description' => 'True if several answers can be kept together.',
                    ],
                ],
                'required' => ['question', 'options'],
            ],
        );
    }
}
