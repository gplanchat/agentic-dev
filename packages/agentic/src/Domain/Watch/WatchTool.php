<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Watch;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

/**
 * The tool through which the agent goes on watch.
 *
 * Like `ask_user`, its execution is a suspension — but what lifts it does not come from a human in
 * front of a card: it is an event from outside, a monitoring system, a webhook, another agent.
 *
 * What a process cannot do: the watch is **journaled**. The agent can sleep for three days, go
 * through a redeployment, and find on waking not only the observation but **the intent it had
 * written when registering**. Nothing to remember, everything is in the journal.
 *
 * The `subject` is what makes the watch reachable: {@see WatchSubjects} holds its vocabulary, and
 * it is the subject — not the `observation`, which is for the human — that a business event will
 * match.
 *
 * Classified `read`: going on watch writes nowhere. What the agent does *afterwards* will go back
 * through the guard like any other call.
 */
final class WatchTool
{
    public const TOOL = 'watch';

    private function __construct()
    {
    }

    private static function catalogue(WatchSubjects $subjects): string
    {
        return implode(', ', array_map(
            static fn (WatchSubject $s): string => \sprintf('`%s` (%s)', $s->value, $s->describe()),
            [...$subjects],
        ));
    }

    public static function definition(WatchSubjects $subjects): ToolDefinition
    {
        return new ToolDefinition(
            self::TOOL,
            'Goes on watch and waits for an outside event to happen. To be used when what comes next '
            .'depends on something that has not happened yet — a delivery, a reply from a supplier, '
            .'a threshold crossed. Execution will resume on the alert, even days later.',
            ToolEffect::Read,
            [
                'type' => 'object',
                'properties' => [
                    'subject' => [
                        'type' => 'string',
                        'enum' => $subjects->values(),
                        'description' => 'The event you are waiting for, to be picked from the list: '
                            .self::catalogue($subjects)
                            .'. It is the subject, and not your sentence, that will wake the watch — a '
                            .'subject off the list will be refused.',
                    ],
                    'observation' => [
                        'type' => 'string',
                        'description' => 'What you are watching for, said in one sentence a human can read.',
                    ],
                    'intent' => [
                        'type' => 'string',
                        'description' => 'What you will do when the alert arrives. Write it now: '
                            .'this is what will be handed back to you on waking, you will not have to remember it.',
                    ],
                    'deadlineSeconds' => [
                        'type' => 'number',
                        'description' => 'Past this delay, the watch is abandoned and you take over.',
                    ],
                ],
                'required' => ['subject', 'observation', 'intent'],
            ],
        );
    }
}
