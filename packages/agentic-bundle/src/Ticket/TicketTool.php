<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Ticket;

use Gplanchat\Agentic\Application\Ticket\Backlog;
use Gplanchat\Agentic\Application\Ticket\HeadProgress;
use Gplanchat\Agentic\Application\Ticket\Tickets;
use Gplanchat\Agentic\Application\Tool\ContextualTool;
use Gplanchat\Agentic\Application\Tool\ToolContext;
use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\Agentic\Domain\Ticket\Ticket;
use Gplanchat\Agentic\Domain\Ticket\TicketMark;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\AgenticBundle\Project\Project;
use Gplanchat\AgenticBundle\Tool\OfferedTool;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One ticket tool ({@see TicketOperation}) on the forge of the project the agent was launched in.
 * Offered only when that project names a ticket tracker.
 */
final readonly class TicketTool implements ContextualTool, OfferedTool
{
    public function __construct(
        private TicketOperation $operation,
        private Project $project,
        /** `null`: an unset `%env(default::…)%` — sent as an empty token. */
        private ?string $token,
        private ?HttpClientInterface $http = null,
    ) {
    }

    public function isOffered(): bool
    {
        return null !== $this->project->tickets;
    }

    public function definition(): ToolDefinition
    {
        return $this->operation->definition();
    }

    public function __invoke(array $arguments): string
    {
        return $this->act($arguments, null, null);
    }

    public function inContext(array $arguments, ToolContext $context): string
    {
        return $this->act($arguments, $context->callId, $context->workspace);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string|null          $callId    what makes opening a ticket survive a retry
     * @param string|null          $workspace the conversation's worktree: who takes a ticket
     */
    private function act(array $arguments, ?string $callId, ?string $workspace): string
    {
        $tracker = $this->project->tickets;
        if (null === $tracker) {
            return 'This project names no ticket tracker (tickets in its .agentic/config.*).';
        }
        $tickets = $tracker->tickets($this->http ?? HttpClient::create(), (string) $this->token);
        $backlog = new Backlog($tickets);

        try {
            return match ($this->operation) {
                TicketOperation::Read => self::read($tickets, $backlog, self::number($arguments, 'ticket')),
                TicketOperation::OpenHead => self::openHead($backlog, $arguments, $callId),
                TicketOperation::OpenWork => self::openWork($backlog, $arguments, $callId),
                TicketOperation::Block => self::block($backlog, self::number($arguments, 'ticket'), self::number($arguments, 'blocker')),
                TicketOperation::Unblock => self::unblock($tickets, self::number($arguments, 'ticket'), self::number($arguments, 'blocker')),
                TicketOperation::Close => self::close($backlog, self::number($arguments, 'head')),
                TicketOperation::Comment => self::comment($backlog, self::number($arguments, 'ticket'), (string) ($arguments['body'] ?? ''), $callId),
                TicketOperation::List => self::overview($backlog),
                TicketOperation::Take => self::take($backlog, self::number($arguments, 'ticket'), null === $workspace ? 'a conversation in the project itself' : 'the conversation of '.basename($workspace), $callId),
                TicketOperation::Release => self::release($backlog, self::number($arguments, 'ticket')),
                TicketOperation::Wait => self::wait($backlog, self::number($arguments, 'ticket'), (string) ($arguments['on'] ?? ''), (string) ($arguments['reason'] ?? ''), $callId),
            };
        } catch (\DomainException $e) {
            // The model's request, or the forge refusing it: handed back, not retried.
            return $e->getMessage();
        }
    }

    private static function read(Tickets $tickets, Backlog $backlog, int $number): string
    {
        $ticket = $tickets->get($number);
        $lines = [self::line($ticket).(null === $ticket->head ? '' : ' — head, '.$ticket->head->value)];
        if ('' !== trim($ticket->body)) {
            $lines[] = trim($ticket->body);
        }
        if ($ticket->isHead()) {
            $work = $tickets->children($number);
            $lines[] = [] === $work ? 'Work: none yet.' : 'Work:';
            foreach ($work as $child) {
                $lines[] = '  '.self::line($child);
            }
        }
        $waits = \array_slice(explode("\n", $backlog->graph($number)->render()), 1);
        if ([] !== $waits) {
            $lines[] = 'Waits on:';
            array_push($lines, ...$waits);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function openHead(Backlog $backlog, array $arguments, ?string $callId): string
    {
        $kind = HeadKind::tryFrom((string) ($arguments['family'] ?? ''));
        if (null === $kind) {
            throw new \DomainException(\sprintf('"family" is one of: %s.', implode(', ', array_column(HeadKind::cases(), 'value'))));
        }
        $time = isset($arguments['time_box']) ? (string) $arguments['time_box'] : null;
        $decision = isset($arguments['decision']) ? (string) $arguments['decision'] : null;
        $ticket = $backlog->openHead($kind, self::title($arguments), (string) ($arguments['body'] ?? ''), $callId, $time, $decision);

        return \sprintf('Opened the %s head #%d.', $kind->value, $ticket->number);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function openWork(Backlog $backlog, array $arguments, ?string $callId): string
    {
        $head = self::number($arguments, 'head');
        $ticket = $backlog->openWork($head, self::title($arguments), (string) ($arguments['body'] ?? ''), $callId);

        return \sprintf('Opened #%d under the head #%d. It closes with its code: commit_worktree with closes %d.', $ticket->number, $head, $ticket->number);
    }

    private static function block(Backlog $backlog, int $ticket, int $blocker): string
    {
        $backlog->block($ticket, $blocker);

        return \sprintf('#%d waits on #%d.', $ticket, $blocker);
    }

    private static function unblock(Tickets $tickets, int $ticket, int $blocker): string
    {
        $tickets->unblock($ticket, $blocker);

        return \sprintf('#%d no longer waits on #%d.', $ticket, $blocker);
    }

    private static function close(Backlog $backlog, int $head): string
    {
        $backlog->closeHead($head);

        return \sprintf('#%d closed as done.', $head);
    }

    private static function comment(Backlog $backlog, int $ticket, string $body, ?string $callId): string
    {
        $backlog->comment($ticket, $body, $callId);

        return \sprintf('Commented on #%d.', $ticket);
    }

    private static function overview(Backlog $backlog): string
    {
        $plan = $backlog->overview();
        $sections = [
            'Heads' => array_map(static fn (HeadProgress $progress): string => \sprintf('%s — %s, %d/%d work closed', self::line($progress->head), $progress->kind->value, $progress->closed, $progress->total), $plan->heads),
            'Ready to take' => array_map(self::line(...), $plan->ready),
            'Taken' => array_map(self::line(...), $plan->taken),
            'Waiting on someone' => array_map(static fn (Ticket $ticket): string => \sprintf('%s (%s)', self::line($ticket), implode(', ', array_map(static fn (TicketMark $mark): string => $mark->value, $ticket->waits()))), $plan->waiting),
            'Waiting on other tickets' => array_map(static fn (int $number, array $on): string => \sprintf('#%d waits on %s', $number, implode(', ', array_map(static fn (int $blocker): string => '#'.$blocker, $on))), array_keys($plan->blocked), $plan->blocked),
            'Work under no open head (EWA-002 § 3)' => array_map(self::line(...), $plan->orphans),
            'Capabilities to scope: no work ticket yet' => array_map(self::line(...), $plan->toSplit),
        ];
        $lines = [];
        foreach (array_filter($sections) as $title => $entries) {
            $lines[] = $title.':';
            foreach ($entries as $entry) {
                $lines[] = '  '.$entry;
            }
        }

        return [] === $lines ? 'No open ticket.' : implode("\n", $lines);
    }

    private static function take(Backlog $backlog, int $ticket, string $by, ?string $callId): string
    {
        $backlog->take($ticket, $by, $callId);

        return \sprintf('#%d is yours.', $ticket);
    }

    private static function wait(Backlog $backlog, int $ticket, string $on, string $reason, ?string $callId): string
    {
        $mark = TicketMark::tryFrom('waits:'.$on);
        if (null === $mark) {
            throw new \DomainException('"on" is one of: author, third-party, measure.');
        }
        $backlog->wait($ticket, $mark, $reason, $callId);

        return \sprintf('#%d waits (%s).', $ticket, $mark->value);
    }

    private static function release(Backlog $backlog, int $ticket): string
    {
        $backlog->release($ticket);

        return \sprintf('#%d is free again.', $ticket);
    }

    private static function line(Ticket $ticket): string
    {
        return \sprintf('#%d %s [%s]', $ticket->number, $ticket->title, $ticket->state->value);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function title(array $arguments): string
    {
        $title = trim((string) ($arguments['title'] ?? ''));
        if ('' === $title) {
            throw new \DomainException('A ticket needs a title.');
        }

        return $title;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function number(array $arguments, string $name): int
    {
        $number = filter_var($arguments[$name] ?? null, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $number) {
            throw new \DomainException(\sprintf('"%s" must be a ticket number.', $name));
        }

        return $number;
    }
}
