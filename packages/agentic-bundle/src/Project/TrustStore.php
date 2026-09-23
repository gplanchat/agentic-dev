<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Project;

use Symfony\Component\Filesystem\Filesystem;

/**
 * The project files a human approved, each with the fingerprint of the bytes they saw — and those
 * bytes, so that a change is shown as a change: one line added among eighty is exactly what a human
 * asked to confirm the eighty would miss.
 *
 * Kept by the installation, not the project: an approval written where the agent works could be
 * written by the agent. Not a cache either — nothing here can be recomputed.
 */
final readonly class TrustStore
{
    public function __construct(private string $path)
    {
    }

    public function isTrusted(ProjectFile $file): bool
    {
        return ($this->read()[$file->path]['sha256'] ?? null) === $file->fingerprint();
    }

    /**
     * Was some version of this file approved? Then what is pending is a change, not a newcomer.
     */
    public function knows(ProjectFile $file): bool
    {
        return \array_key_exists($file->path, $this->read());
    }

    /**
     * What the human approved last time, to show the change against; `null` when nothing was, or when
     * the approval predates the store keeping it.
     */
    public function approvedContents(ProjectFile $file): ?string
    {
        return $this->read()[$file->path]['contents'] ?? null;
    }

    public function trust(ProjectFile $file): void
    {
        $approval = ['sha256' => $file->fingerprint(), 'contents' => $file->contents];
        (new Filesystem())->dumpFile($this->path, json_encode([...$this->read(), $file->path => $approval], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @return array<string, array{sha256: string, contents?: string}> path → approval
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $trusted = json_decode((string) file_get_contents($this->path), true);
        $approvals = [];
        foreach (\is_array($trusted) ? $trusted : [] as $path => $approval) {
            // A bare sha256: written before the contents were kept. Still an approval.
            if (\is_string($approval)) {
                $approvals[(string) $path] = ['sha256' => $approval];
            } elseif (\is_array($approval) && \is_string($approval['sha256'] ?? null)) {
                $approvals[(string) $path] = ['sha256' => $approval['sha256'], ...(\is_string($approval['contents'] ?? null) ? ['contents' => $approval['contents']] : [])];
            }
        }

        return $approvals;
    }
}
