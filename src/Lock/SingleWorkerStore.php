<?php

declare(strict_types=1);

namespace App\Lock;

use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Key;

/**
 * Un magasin de verrous qui accorde toujours : la TUI est le seul processus qui fait tourner les
 * workflows, il n'y a donc jamais deux reprises concurrentes d'une même exécution à sérialiser.
 *
 * Pourquoi pas `flock` : `SingleResumeLockMiddleware` de durable-bundle verrouille aussi à l'**envoi**
 * d'un `ResumeWorkflowMessage` ou d'un `FireWorkflowTimersMessage`. Une reprise qui planifie le
 * minuteur de sa propre exécution redemande alors un verrou qu'elle tient déjà — et `flock` bloque
 * pour toujours dans le même processus.
 *
 * ponytail: plafond assumé — faux dès qu'un second worker (`messenger:consume`, la version web)
 * fait tourner les mêmes exécutions. Ce jour-là : un magasin partagé, et le middleware corrigé pour
 * ne verrouiller qu'à la réception.
 */
final class SingleWorkerStore implements BlockingStoreInterface
{
    public function save(Key $key): void
    {
        // Chaque demande est accordée, même pour une clé déjà tenue par un autre objet verrou : c'est
        // tout l'objet de ce magasin. L'état vit sur la clé, pour que `release()` constate la libération.
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
