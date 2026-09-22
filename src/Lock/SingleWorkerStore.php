<?php

declare(strict_types=1);

namespace App\Lock;

use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Key;

/**
 * A lock store that always grants: the TUI is the only process running workflows, so there are never
 * two concurrent resumes of one execution to serialise.
 *
 * Why not `flock`: durable-bundle's `SingleResumeLockMiddleware` also locks when a
 * `ResumeWorkflowMessage` or a `FireWorkflowTimersMessage` is **sent**. A resume that schedules the
 * timer of its own execution then asks again for a lock it already holds — and `flock` blocks
 * forever inside one process. See gplanchat/durable-dev#417.
 *
 * ponytail: ceiling taken knowingly — wrong as soon as a second worker (`messenger:consume`, the web
 * version) runs the same executions. That day: a shared store, and the middleware fixed to lock on
 * reception only.
 */
final class SingleWorkerStore implements BlockingStoreInterface
{
    public function save(Key $key): void
    {
        // Every request is granted, even for a key another lock object already holds: that is the
        // whole point of this store. The state lives on the key, so `release()` sees the release.
        $key->setState(self::class, true);
    }

    public function waitAndSave(Key $key): void
    {
        $this->save($key);
    }

    public function delete(Key $key): void
    {
        $key->removeState(self::class);
    }

    public function exists(Key $key): bool
    {
        return $key->hasState(self::class);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
    }
}
