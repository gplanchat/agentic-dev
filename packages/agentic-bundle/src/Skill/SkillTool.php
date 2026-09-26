<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Skill;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\AgenticBundle\Project\Project;
use Gplanchat\AgenticBundle\Tool\OfferedTool;

/**
 * Hands the agent the procedure of a skill. Its description is the index: every skill's name and
 * what it is for, always in view; the procedure itself only when asked for.
 *
 * Offered only when the project names a ticket tracker: these skills work on its tickets.
 */
final readonly class SkillTool implements OfferedTool
{
    public const TOOL = 'skill';

    public function __construct(
        private Skills $skills,
        private Project $project,
    ) {
    }

    public function isOffered(): bool
    {
        return null !== $this->project->tickets;
    }

    public function definition(): ToolDefinition
    {
        $index = [];
        foreach ($this->skills as $skill) {
            $index[] = \sprintf('`%s` — %s', $skill->name, $skill->description);
        }

        return new ToolDefinition(
            self::TOOL,
            'Loads the procedure of a skill, before doing the work it covers — then follow it. Skills: '.implode(' ', $index),
            ToolEffect::Read,
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'enum' => $this->skills->names()]], 'required' => ['name']],
        );
    }

    public function __invoke(array $arguments): string
    {
        $skill = $this->skills->get((string) ($arguments['name'] ?? ''));

        return null !== $skill ? $skill->procedure : \sprintf('No such skill. Skills: %s.', implode(', ', $this->skills->names()));
    }
}
