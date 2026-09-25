<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Ticket;

use Gplanchat\Agentic\Application\Ticket\Tickets;
use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\Agentic\Domain\Ticket\Ticket;
use Gplanchat\Agentic\Domain\Ticket\TicketState;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A Forgejo's issues, over its API v1. The repository must have dependencies enabled (its settings,
 * "Issues").
 *
 * Forgejo has no sub-issues: a work ticket names its head on a line of its body, `Head: #12`, and
 * the head is blocked by it — so Forgejo itself refuses to close a head over open work (EWA-002
 * § 7.5). A head's dependencies are therefore of two kinds, told apart by that line: its work, and
 * what it really waits on.
 *
 * Forgejo does not say why an issue was closed: every closed issue counts as done here.
 */
final readonly class ForgejoTickets implements Tickets
{
    /**
     * @param string $url the forge's root, e.g. https://codeberg.org
     */
    public function __construct(
        private HttpClientInterface $http,
        private string $url,
        private string $repository,
        private string $token,
        private HeadLabels $labels = new HeadLabels(),
    ) {
    }

    public function get(int $number): Ticket
    {
        return $this->ticket($this->request('GET', '/issues/'.$number));
    }

    public function blockers(int $number): array
    {
        return array_values(array_filter($this->dependencies($number), static fn (Ticket $ticket): bool => $number !== self::headOf($ticket)));
    }

    public function children(int $head): array
    {
        return array_values(array_filter($this->dependencies($head), static fn (Ticket $ticket): bool => $head === self::headOf($ticket)));
    }

    public function recent(): array
    {
        return $this->tickets($this->request('GET', '/issues?state=all&type=issues&sort=latest&limit=50'));
    }

    public function openHead(HeadKind $kind, string $title, string $body): Ticket
    {
        $label = $this->labels->of($kind);
        $id = null;
        foreach ($this->request('GET', '/labels?limit=100') as $candidate) {
            $name = \is_array($candidate) ? ($candidate['name'] ?? null) : null;
            if (\is_string($name) && 0 === strcasecmp($name, $label)) {
                $id = $candidate['id'] ?? null;
            }
        }
        if (!\is_int($id)) {
            throw new \DomainException(\sprintf('The repository has no label "%s" for the %s family: create it, or name another in tickets.labels.', $label, $kind->value));
        }

        return $this->ticket($this->request('POST', '/issues', ['title' => $title, 'body' => $body, 'labels' => [$id]]));
    }

    public function openWork(int $head, string $title, string $body): Ticket
    {
        return $this->ticket($this->request('POST', '/issues', ['title' => $title, 'body' => rtrim($body)."\n\nHead: #".$head]));
    }

    public function adopt(int $head, int $work): void
    {
        $named = self::headOf($this->get($work));
        if ($head !== $named) {
            throw new \DomainException(null === $named
                ? \sprintf('#%d names no head: add "Head: #%d" to its body first.', $work, $head)
                : \sprintf('#%d hangs under #%d already: a work ticket has one head.', $work, $named));
        }
        foreach ($this->dependencies($head) as $dependency) {
            if ($work === $dependency->number) {
                return;
            }
        }
        $this->block($head, $work);
    }

    public function close(int $number): void
    {
        $this->request('PATCH', '/issues/'.$number, ['state' => 'closed']);
    }

    public function block(int $number, int $by): void
    {
        $this->request('POST', '/issues/'.$number.'/dependencies', $this->meta($by));
    }

    public function unblock(int $number, int $by): void
    {
        $this->request('DELETE', '/issues/'.$number.'/dependencies', $this->meta($by));
    }

    /**
     * Every issue `$number` depends on, all of this repository: another repository's #5 is not ours
     * — read as ours, a closed local #5 would unblock, or let a head close.
     *
     * @return list<Ticket>
     */
    private function dependencies(int $number): array
    {
        $issues = $this->request('GET', '/issues/'.$number.'/dependencies?limit=100');
        foreach ($issues as $issue) {
            $repository = \is_array($issue) ? ($issue['repository']['full_name'] ?? null) : null;
            if (!\is_string($repository) || 0 !== strcasecmp($repository, $this->repository)) {
                throw new \DomainException(\sprintf('#%d is linked to an issue of another repository (%s): a human must handle that link.', $number, json_encode($repository)));
            }
        }

        return $this->tickets($issues);
    }

    /**
     * The head a ticket names, `Head: #12` on a line of its own. Anyone can edit a body: two heads
     * named is refused, never settled by picking one.
     */
    private static function headOf(Ticket $ticket): ?int
    {
        preg_match_all('/^Head: #(\d+)[ \t]*$/m', $ticket->body, $matches);
        // array_unique() keeps the first of each: the first head, if any, stays at 0.
        $heads = array_unique(array_map('intval', $matches[1]));
        if (\count($heads) > 1) {
            throw new \DomainException(\sprintf('#%d names two heads (%s): a work ticket has one — a human must settle it.', $ticket->number, implode(', ', array_map(static fn (int $head): string => '#'.$head, $heads))));
        }

        return $heads[0] ?? null;
    }

    /**
     * @return array{owner: string, repo: string, index: int}
     */
    private function meta(int $number): array
    {
        [$owner, $repo] = explode('/', $this->repository);

        return ['owner' => $owner, 'repo' => $repo, 'index' => $number];
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $response = $this->http->request($method, rtrim($this->url, '/').'/api/v1/repos/'.$this->repository.$path, [
            'headers' => ['Accept' => 'application/json', 'Authorization' => 'token '.$this->token],
            ...(null === $json ? [] : ['json' => $json]),
        ]);

        return 204 === $response->getStatusCode() ? [] : $response->toArray();
    }

    /**
     * @param array<mixed> $issues
     *
     * @return list<Ticket>
     */
    private function tickets(array $issues): array
    {
        // Dependencies may be pull requests: they are not tickets.
        $issues = array_filter($issues, static fn (mixed $issue): bool => \is_array($issue) && empty($issue['pull_request']));

        return array_values(array_map($this->ticket(...), $issues));
    }

    /**
     * @param array<mixed> $issue
     */
    private function ticket(array $issue): Ticket
    {
        $state = match ($issue['state'] ?? null) {
            'open' => TicketState::Open,
            'closed' => TicketState::Done,
            default => throw new \UnexpectedValueException(\sprintf('Unknown Forgejo issue state: %s.', json_encode($issue['state'] ?? null))),
        };
        if (!\is_int($issue['number'] ?? null) || !\is_string($issue['title'] ?? null)) {
            throw new \UnexpectedValueException('An issue without its number or title.');
        }
        $labels = [];
        foreach (\is_array($issue['labels'] ?? null) ? $issue['labels'] : [] as $label) {
            $name = \is_array($label) ? ($label['name'] ?? null) : null;
            if (\is_string($name)) {
                $labels[] = $name;
            }
        }

        return new Ticket($issue['number'], $issue['title'], $state, \is_string($issue['body'] ?? null) ? $issue['body'] : '', $this->labels->kindOf($labels));
    }
}
