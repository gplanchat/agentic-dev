<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Team;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

/**
 * The tool through which one agent puts another to work.
 *
 * A sub-agent is a **child workflow**: its own execution, its own journal, its own model. That is
 * what makes it possible to assemble a team where everyone has the model that suits them — a small
 * one to sort, a big one to write — without the parent having to know how the other one is made.
 *
 * **What delegating does not hand over: authority.** The delegate inherits its parent's effective
 * mode as a ceiling ({@see \Gplanchat\Agentic\Domain\Guard\AgentMode::strictest()}), and nothing loosens it. Otherwise
 * an agent in `standard` would hand to a sub-agent in `auto` what its guard refuses it, and the
 * guard would be nothing but decoration.
 *
 * An accepted consequence, and it is what makes the thing safe without one more rule: under a
 * `standard` ceiling, a sub-agent can only read — hence nothing that needs an approval nobody is
 * there to give it. Nobody watches a sub-agent; so it is allowed to do nothing irreversible,
 * unless a human explicitly put the chain in `auto`.
 *
 * Classified `read`: delegating writes nowhere. What the delegate will do goes back through its
 * own guard, under the inherited ceiling.
 */
final class DelegateTool
{
    public const TOOL = 'delegate';

    private function __construct()
    {
    }

    public static function definition(AgentProfiles $profiles = new AgentProfiles()): ToolDefinition
    {
        $named = [];
        foreach ($profiles as $profile) {
            $named[] = \sprintf('`%s` (%s)', $profile->name, $profile->description);
        }

        return new ToolDefinition(
            self::TOOL,
            'Hands a mission to a sub-agent and waits for its reply. To be used when the task gains '
            .'from being handled apart — another model, a clean context, a line of reasoning that '
            .'does not have to clutter yours. The sub-agent can never do more than what your own '
            .'guard allows you.',
            ToolEffect::Read,
            [
                'type' => 'object',
                'properties' => [
                    'mission' => [
                        'type' => 'string',
                        'description' => 'What the sub-agent must do, in full: it sees nothing of '
                            .'your conversation, only this sentence.',
                    ],
                    'agent' => [
                        'type' => 'string',
                        'enum' => $profiles->names(),
                        'description' => [] === $named
                            ? 'No sub-agent is declared by this application; omit this.'
                            : 'Which sub-agent takes the mission, to pick from: '.implode(', ', $named)
                                .'. Each comes with its own model, its own instructions and its own tools. '
                                .'Omit for a sub-agent with no tools, on your model.',
                    ],
                    'model' => [
                        'type' => 'string',
                        'description' => 'The model to hand it, when no sub-agent is named. Omit to take yours again.',
                    ],
                ],
                'required' => ['mission'],
            ],
        );
    }
}
