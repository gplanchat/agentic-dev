<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Project;

use Gplanchat\AgenticBundle\Ticket\Forge;
use Gplanchat\AgenticBundle\Ticket\HeadLabels;
use Gplanchat\AgenticBundle\Ticket\TicketTracker;
use Symfony\Component\Config\Definition\Processor;

/**
 * Reads the project the agent is launched in: the installation's configuration, and the project's
 * `.agentic/config.*` laid over it — once a human approved that very file.
 */
final readonly class ProjectLoader
{
    /**
     * @param array{checks: array<string, array{command: list<string>, cwd: string, filter_option: string|null, timeout_seconds: float, description: string, tests: string, review: list<string>}>, hidden: list<string>, shared: list<string>, auto_allow: list<string>, worktrees: bool} $installation
     */
    public function __construct(
        private TrustStore $trust,
        private array $installation,
    ) {
    }

    /**
     * The project file waiting for an approval: new, or changed since it was approved.
     *
     * @throws InvalidProjectConfiguration
     */
    public function pending(string $root): ?ProjectFile
    {
        $file = ProjectFile::find($root);

        return null === $file || $this->trust->isTrusted($file) ? null : $file;
    }

    /**
     * @throws InvalidProjectConfiguration
     */
    public function load(string $root): Project
    {
        $file = ProjectFile::find($root);
        if (null !== $file && !$this->trust->isTrusted($file)) {
            // Not approved — or changed since: none of it is used, not a layer, not a rule.
            $file = null;
        }
        $settings = null !== $file ? $file->settings : (new Processor())->processConfiguration(new ProjectConfiguration(), []);

        $checks = [...$this->installation['checks'], ...$settings['checks']];
        foreach ($checks as $name => $layer) {
            if ([] !== $unknown = array_diff($layer['review'], array_keys($checks))) {
                throw new InvalidProjectConfiguration(\sprintf('%s: the review of the check layer "%s" names unknown layers: %s.', $file->path ?? 'the installation configuration', $name, implode(', ', $unknown)));
            }
        }

        $instructions = trim((string) $settings['instructions_file']);

        $tickets = null;
        if (isset($settings['tickets'])) {
            try {
                $tickets = new TicketTracker(Forge::from($settings['tickets']['forge']), $settings['tickets']['repository'], $settings['tickets']['url'], new HeadLabels($settings['tickets']['labels']));
            } catch (\InvalidArgumentException $e) {
                throw new InvalidProjectConfiguration(\sprintf('%s: %s', $file->path ?? 'the project configuration', $e->getMessage()), 0, $e);
            }
        }

        return new Project(
            $root,
            $checks,
            array_values($settings['tool_rules']),
            self::join($this->installation['hidden'], $settings['sandbox']['hidden']),
            self::join($this->installation['shared'], $settings['sandbox']['shared']),
            self::join($this->installation['auto_allow'], $settings['sandbox']['auto_allow']),
            $settings['sandbox']['worktrees'] ?? $this->installation['worktrees'],
            '' === $instructions ? null : $root.'/'.ltrim($instructions, '/'),
            $file,
            $tickets,
        );
    }

    /**
     * @param list<string> $installation
     * @param list<string> $project
     *
     * @return list<string>
     */
    private static function join(array $installation, array $project): array
    {
        return array_values(array_unique([...$installation, ...$project]));
    }
}
