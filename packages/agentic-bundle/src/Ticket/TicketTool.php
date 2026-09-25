<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Ticket;

use Gplanchat\Agentic\Application\Ticket\Backlog;
use Gplanchat\Agentic\Application\Ticket\Tickets;
use Gplanchat\Agentic\Application\Tool\ContextualTool;
use Gplanchat\Agentic\Application\Tool\ToolContext;
use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\Agentic\Domain\Ticket\Ticket;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\AgenticBundle\Project\Project;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One ticket tool ({@see TicketOperation}) on the forge of the project the agent was launched in.
 * Offered only when that project names a ticket tracker.
 */
final readonly class TicketTool implements ContextualTool
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
        return $this->act($arguments, null);
    }

    public function inContext(array $arguments, ToolContext $context): string
    {
        return $this->act($arguments, $context->callId);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string|null          $callId    what makes opening a ticket survive a retry
     */
    private function act(array $arguments, ?string $callId): string
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
