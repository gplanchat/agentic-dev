<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Identity;

/**
 * Who a conversation belongs to, and on whose behalf its tools act.
 *
 * A value object and not the framework's user: the domain speaks no `UserInterface`
 * ({@see \Gplanchat\Agentic\Tests\ArchitectureTest}), and above all this travels **in the workflow
 * payload** — it is journaled, replayed, carried over by `continueAsNew`. What goes into the
 * journal must be data, not a service the container of the day would have to hand back.
 *
 * `roles` is the substrate a narrower identity is cut from: a sub-agent takes its parent's
 * principal, never a wider one, exactly as {@see \Gplanchat\Agentic\Domain\Guard\AgentMode::strictest()}
 * does for the mode. That narrowing is not here yet — nothing consults `roles` so far; it is
 * carried from the start so that adding it later does not change the shape of a payload already
 * written to the journal.
 */
final readonly class Principal
{
    /**
     * @param list<string> $roles what this principal may claim; the guard does not read them yet
     */
    public function __construct(
        public string $id,
        public array $roles = [],
    ) {
        if ('' === trim($id)) {
            throw new \InvalidArgumentException('A principal must carry an identifier.');
        }
    }

    /**
     * Identity, not equivalence: two principals with the same id are the same person, whatever
     * roles the session of the day gives them. Ownership is decided on the id alone — a role lost
     * between two sessions must not lock someone out of their own conversation.
     */
    public function is(self $other): bool
    {
        return $this->id === $other->id;
    }

    /**
     * The same person, holding less — **authority does not grow by delegation**.
     *
     * An intersection, never a replacement: a profile that names a role its caller does not hold
     * grants nothing, exactly as {@see \Gplanchat\Agentic\Domain\Guard\AgentMode::strictest()}
     * refuses to loosen a ceiling. Without that, declaring a sub-agent would be the way to hand it
     * what one may not do oneself, and the guard would be decoration on the identity axis just as
     * it would on the mode one.
     *
     * The `id` does not move: a sub-agent acts *for* the same person, and the journal must keep
     * saying whose work it was. Only what they may claim narrows.
     *
     * @param list<string> $roles what the profile allows; the result keeps only those this
     *                            principal already had
     */
    public function restrictedTo(array $roles): self
    {
        return new self($this->id, array_values(array_intersect($this->roles, $roles)));
    }

    /**
     * `null` when the journal carries no owner: a conversation opened before this existed. The
     * caller decides what to make of it — {@see \Gplanchat\Agentic\Application\Chat\Conversations}
     * treats an unowned conversation as nobody's, hence everybody's to refuse.
     */
    public static function fromWire(mixed $wire): ?self
    {
        if (!\is_array($wire) || !\is_string($wire['id'] ?? null) || '' === trim($wire['id'])) {
            return null;
        }

        $roles = $wire['roles'] ?? [];

        return new self($wire['id'], array_values(array_map(strval(...), \is_array($roles) ? $roles : [])));
    }

    /**
     * @return array{id: string, roles?: list<string>}
     */
    public function toWire(): array
    {
        // `roles` only goes out when it says something: an owner with no role keeps the same shape
        // in the journal as the day it was written.
        return [] === $this->roles ? ['id' => $this->id] : ['id' => $this->id, 'roles' => $this->roles];
    }
}
