<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Ticket;

/**
 * The forges whose tickets the agent can work with: one adapter each.
 */
enum Forge: string
{
    case GitHub = 'github';
    case Forgejo = 'forgejo';
}
