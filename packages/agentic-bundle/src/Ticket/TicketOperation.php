<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Ticket;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

/**
 * The ticket tools, one per case: what the model can do with the project's plan on its forge.
 *
 * Reading is `read`. Everything that writes to the forge is `external` — it leaves the perimeter
 * and no worktree reset takes it back.
 */
enum TicketOperation: string
{
    case Read = 'ticket_read';
    case OpenHead = 'ticket_open_head';
    case OpenWork = 'ticket_open_work';
    case Block = 'ticket_block';
    case Unblock = 'ticket_unblock';
    case Close = 'ticket_close';
    case Comment = 'ticket_comment';

    public function definition(): ToolDefinition
    {
        $number = static fn (string $what): array => ['type' => 'integer', 'minimum' => 1, 'description' => $what];
        $text = static fn (string $what): array => ['type' => 'string', 'description' => $what];
        $link = static fn (string $blocker): array => ['type' => 'object', 'properties' => ['ticket' => $number('The ticket that waits.'), 'blocker' => $number($blocker)], 'required' => ['ticket', 'blocker']];

        return match ($this) {
            self::Read => new ToolDefinition(
                $this->value,
                'Reads a ticket of the project\'s plan, kept on its forge: its family if it is a head, its work tickets with their state, and what it waits on, marking what can be taken now (READY). A head is what matters to whoever pays — a capability, a defect, a debt, groundwork, an investigation. A work ticket is one task a person finishes in a day, with its proof: one OpenSpec task N.M, where the time is logged.',
                ToolEffect::Read,
                ['type' => 'object', 'properties' => ['ticket' => $number('The ticket number.')], 'required' => ['ticket']],
            ),
            self::OpenHead => new ToolDefinition(
                $this->value,
                'Opens a head ticket. Its family is chosen by what the work changes, never by its size, first answer wins: something behaves otherwise than written → defect; it pays back a past choice → debt; it prepares what comes, with no visible value today → groundwork; its result is to know → investigation; otherwise → capability. A capability gets work tickets; the others need one only when more than one person must share them.',
                ToolEffect::External,
                [
                    'type' => 'object',
                    'properties' => [
                        'family' => ['type' => 'string', 'enum' => array_column(HeadKind::cases(), 'value')],
                        'title' => ['type' => 'string'],
                        'body' => $text('What is asked, for whom, and why.'),
                        'time_box' => $text('An investigation only, and required there: how long it may take.'),
                        'decision' => $text('An investigation only, and required there: the decision it must enable.'),
                    ],
                    'required' => ['family', 'title'],
                ],
            ),
            self::OpenWork => new ToolDefinition(
                $this->value,
                'Opens a work ticket under an open head: one task a person finishes in a day, with its proof — one OpenSpec task N.M. A Mikado prerequisite too big for the task at hand becomes one.',
                ToolEffect::External,
                [
                    'type' => 'object',
                    'properties' => [
                        'head' => $number('The head ticket it belongs to.'),
                        'title' => $text('Starts with the task id, e.g. "3.2 Forgejo adapter".'),
                        'body' => $text('What must be true once it is done, and how to prove it.'),
                    ],
                    'required' => ['head', 'title'],
                ],
            ),
            self::Block => new ToolDefinition(
                $this->value,
                'Makes a ticket wait on another (it is blocked by it) — an OpenSpec "⛔ attend:" between tickets. Refused when it would close a cycle.',
                ToolEffect::External,
                $link('The ticket to finish first.'),
            ),
            self::Unblock => new ToolDefinition(
                $this->value,
                'Stops a ticket waiting on another — when the wait turned out not to be needed.',
                ToolEffect::External,
                $link('The ticket it no longer waits on.'),
            ),
            self::Close => new ToolDefinition(
                $this->value,
                'Closes a head ticket as done, once all its work is closed. A work ticket is not closed here: it closes with its code, through commit_worktree with closes.',
                ToolEffect::External,
                ['type' => 'object', 'properties' => ['head' => $number('The head ticket done.')], 'required' => ['head']],
            ),
            self::Comment => new ToolDefinition(
                $this->value,
                'Posts a comment on a ticket. At the end of a task, post its Mikado graph (mikado_show) on its work ticket: the trace of what the task required.',
                ToolEffect::External,
                ['type' => 'object', 'properties' => ['ticket' => $number('The ticket to comment on.'), 'body' => $text('The comment, in Markdown.')], 'required' => ['ticket', 'body']],
            ),
        };
    }
}
