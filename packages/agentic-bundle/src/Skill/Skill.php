<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Skill;

/**
 * A procedure the agent follows for one kind of work — framing a need, delivering a ticket, having
 * it judged. The index (its name and description) is always in view; the procedure is loaded when
 * the work comes: progressive disclosure, so a long procedure costs nothing until it is used.
 */
final readonly class Skill
{
    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public string $name,
        public string $description,
        /** What the command takes, as `/help` shows it: `[#work ticket]`, `<the need>`; `''`: nothing. */
        public string $argument,
        public string $procedure,
    ) {
        if (1 !== preg_match('/^[a-z][a-z-]*$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('A skill is named in lowercase letters and dashes, not "%s".', $name));
        }
        if ('' === trim($description) || '' === trim($procedure)) {
            throw new \InvalidArgumentException(\sprintf('The skill "%s" needs a description and a procedure.', $name));
        }
    }
}
