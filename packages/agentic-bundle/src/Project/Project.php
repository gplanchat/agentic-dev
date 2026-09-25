<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Project;

use Gplanchat\AgenticBundle\Ticket\TicketTracker;

/**
 * The project the agent was launched in, as it will work on it: the installation's configuration
 * with the approved `.agentic/config.*` laid over it.
 */
final readonly class Project
{
    /**
     * @param array<string, array{command: list<string>, cwd: string, filter_option: string|null, timeout_seconds: float, description: string, tests: string, review: list<string>}> $checks
     * @param list<array<string, mixed>>                                                                                                                                              $toolRules the project's own, on top of the installation's
     * @param list<string>                                                                                                                                                            $hidden
     * @param list<string>                                                                                                                                                            $shared
     * @param list<string>                                                                                                                                                            $autoAllow
     */
    public function __construct(
        public string $root,
        public array $checks,
        public array $toolRules,
        public array $hidden,
        public array $shared,
        public array $autoAllow,
        public bool $worktrees,
        public ?string $instructionsFile,
        public ?ProjectFile $file,
        /** Where the project keeps its tickets; `null`: no ticket tools. */
        public ?TicketTracker $tickets = null,
    ) {
    }
}
