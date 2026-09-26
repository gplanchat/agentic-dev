<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Ticket;

/**
 * The family of a head ticket — the unit that makes sense to whoever pays (EWA-002 § 1). Chosen by
 * what the work changes, never by its size (§ 2): does something behave otherwise than written? a
 * defect. Does it pay back a past choice? debt. Does it prepare what comes, with no visible value
 * today? groundwork. Otherwise a capability. An investigation's result is to know, not to deliver.
 *
 * The values are Archibald's (`HeadKind` in its glossary); the labels a forge shows are the
 * project's, per EWA-002 § 1.
 */
enum HeadKind: string
{
    case Defect = 'defect';
    case Debt = 'debt';
    case Groundwork = 'groundwork';
    case Capability = 'capability';
    case Investigation = 'investigation';
}
