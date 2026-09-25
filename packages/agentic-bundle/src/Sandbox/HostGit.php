<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Sandbox;

use Symfony\Component\Process\Process;

/**
 * Git on the host, in a worktree the agent writes — for what the sandbox cannot do there, its git
 * directory being read-only.
 *
 * No hook, no fsmonitor: a relative core.hooksPath (Husky's) or fsmonitor command resolves inside
 * the worktree, so it would run the agent's own code outside the sandbox.
 */
final class HostGit
{
    private const SAFE = ['-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false'];

    /**
     * @param list<string>          $arguments the command and its arguments
     * @param array<string, string> $config    more settings for this call
     *
     * @throws \RuntimeException when git fails: an infrastructure failure, retried like one
     */
    public static function run(string $worktree, array $arguments, array $config = []): string
    {
        $settings = [];
        foreach ($config as $name => $value) {
            array_push($settings, '-c', $name.'='.$value);
        }
        $git = new Process(['git', '-C', $worktree, ...self::SAFE, ...$settings, ...$arguments]);
        $git->run();
        if (!$git->isSuccessful()) {
            throw new \RuntimeException(\sprintf('git %s failed in %s: %s', $arguments[0] ?? '', $worktree, trim($git->getErrorOutput())));
        }

        return $git->getOutput();
    }

    /**
     * The conversation's own worktree, cut already — or why there is none to act on.
     *
     * @return string|null `null` when the workspace is one: the reason otherwise
     */
    public static function refusal(Workspaces $workspaces, ?string $workspace): ?string
    {
        $worktrees = $workspaces->worktrees();
        if (null === $workspace || null === $worktrees || !$worktrees->owns($workspace)) {
            return 'Nothing done: this conversation works in the project itself, not in a worktree of its own — that is the human\'s work.';
        }

        return is_dir($workspace) ? null : 'Nothing done: the worktree does not exist yet.';
    }
}
