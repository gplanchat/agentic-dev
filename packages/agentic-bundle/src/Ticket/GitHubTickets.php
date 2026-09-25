<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Ticket;

use Gplanchat\Agentic\Application\Ticket\Tickets;
use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\Agentic\Domain\Ticket\Ticket;
use Gplanchat\Agentic\Domain\Ticket\TicketState;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GitHub's issues, over its REST API: a work ticket is a sub-issue of its head — a tree with one
 * parent, as EWA-002 § 3 reads it —, a head carries its family's label, and what waits on what is an
 * issue dependency ("blocked by").
 */
final readonly class GitHubTickets implements Tickets
{
    public function __construct(
        private HttpClientInterface $http,
        private string $repository,
        private string $token,
        private HeadLabels $labels = new HeadLabels(),
        private string $api = 'https://api.github.com',
    ) {
    }

    public function get(int $number): Ticket
    {
        return $this->ticket($this->request('GET', '/issues/'.$number));
    }

    public function blockers(int $number): array
    {
        return $this->local($this->request('GET', '/issues/'.$number.'/dependencies/blocked_by?per_page=100'), $number, 'blocked by');
    }

    public function children(int $head): array
    {
        // ponytail: one page of 100 sub-issues. A head with more has outgrown EWA-002 § 2 anyway.
        return $this->local($this->request('GET', '/issues/'.$head.'/sub_issues?per_page=100'), $head, 'the head of');
    }

    public function recent(): array
    {
        return $this->tickets($this->request('GET', '/issues?state=all&sort=created&direction=desc&per_page=50'));
    }

    public function openHead(HeadKind $kind, string $title, string $body): Ticket
    {
        $label = $this->labels->of($kind);
        // An unknown label would be created by the issue itself: a typo would invent a family.
        if (200 !== $this->response('GET', '/labels/'.rawurlencode($label))->getStatusCode()) {
            throw new \DomainException(\sprintf('The forge has no label "%s" for the %s family: create it, or name another in tickets.labels.', $label, $kind->value));
        }

        return $this->ticket($this->request('POST', '/issues', ['title' => $title, 'body' => $body, 'labels' => [$label]]));
    }

    public function openWork(int $head, string $title, string $body): Ticket
    {
        return $this->ticket($this->request('POST', '/issues', ['title' => $title, 'body' => $body]));
    }

    public function adopt(int $head, int $work): void
    {
        $parent = $this->response('GET', '/issues/'.$work.'/parent');
        if (200 === $parent->getStatusCode()) {
            $number = $parent->toArray()['number'] ?? null;
            if ($head !== $number) {
                throw new \DomainException(\sprintf('#%d hangs under #%s already: a work ticket has one head.', $work, json_encode($number)));
            }

            return;
        }
        // Never `replace_parent`: moving a leaf to another head is a human's decision.
        $this->request('POST', '/issues/'.$head.'/sub_issues', ['sub_issue_id' => $this->idOf($work)]);
    }

    public function close(int $number): void
    {
        $this->request('PATCH', '/issues/'.$number, ['state' => 'closed', 'state_reason' => 'completed']);
    }

    public function block(int $number, int $by): void
    {
        // The link takes the blocker's internal id, not the number humans type.
        $this->request('POST', '/issues/'.$number.'/dependencies/blocked_by', ['issue_id' => $this->idOf($by)]);
    }

    public function unblock(int $number, int $by): void
    {
        $this->request('DELETE', '/issues/'.$number.'/dependencies/blocked_by/'.$this->idOf($by));
    }

    public function comments(int $number): array
    {
        $bodies = [];
        foreach ($this->request('GET', '/issues/'.$number.'/comments?per_page=100') as $comment) {
            if (\is_array($comment) && \is_string($comment['body'] ?? null)) {
                $bodies[] = $comment['body'];
            }
        }

        return $bodies;
    }

    public function comment(int $number, string $body): void
    {
        $this->request('POST', '/issues/'.$number.'/comments', ['body' => $body]);
    }

    private function idOf(int $number): int
    {
        $id = $this->request('GET', '/issues/'.$number)['id'] ?? null;
        if (!\is_int($id)) {
            throw new \UnexpectedValueException(\sprintf('GitHub gave no id for the issue #%d.', $number));
        }

        return $id;
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function response(string $method, string $path, ?array $json = null): ResponseInterface
    {
        return $this->http->request($method, $this->api.'/repos/'.$this->repository.$path, [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => 'Bearer '.$this->token,
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
            ...(null === $json ? [] : ['json' => $json]),
        ]);
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $response = $this->response($method, $path, $json);

        return 204 === $response->getStatusCode() ? [] : $response->toArray();
    }

    /**
     * Issues linked to `$number`, all of this repository: another repository's #5 is not ours — read
     * as ours, a closed local #5 would unblock, or let a head close.
     *
     * @param array<mixed> $issues
     *
     * @return list<Ticket>
     */
    private function local(array $issues, int $number, string $relation): array
    {
        foreach ($issues as $issue) {
            $url = \is_array($issue) ? ($issue['repository_url'] ?? null) : null;
            if (!\is_string($url) || !str_ends_with(strtolower($url), strtolower('/repos/'.$this->repository))) {
                throw new \DomainException(\sprintf('#%d is %s an issue of another repository (%s): a human must handle that link.', $number, $relation, json_encode($url)));
            }
        }

        return $this->tickets($issues);
    }

    /**
     * @param array<mixed> $issues
     *
     * @return list<Ticket>
     */
    private function tickets(array $issues): array
    {
        // The issues endpoints list pull requests too: they are not tickets.
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
            // Not planned, or a duplicate: closed, but the work was never done.
            'closed' => \in_array($issue['state_reason'] ?? null, ['not_planned', 'duplicate'], true) ? TicketState::Abandoned : TicketState::Done,
            default => throw new \UnexpectedValueException(\sprintf('Unknown GitHub issue state: %s.', json_encode($issue['state'] ?? null))),
        };
        if (!\is_int($issue['number'] ?? null) || !\is_string($issue['title'] ?? null)) {
            throw new \UnexpectedValueException('An issue without its number or title.');
        }

        return new Ticket($issue['number'], $issue['title'], $state, \is_string($issue['body'] ?? null) ? $issue['body'] : '', $this->labels->kindOf(self::labelNames($issue)));
    }

    /**
     * @param array<mixed> $issue
     *
     * @return list<string>
     */
    private static function labelNames(array $issue): array
    {
        $names = [];
        foreach (\is_array($issue['labels'] ?? null) ? $issue['labels'] : [] as $label) {
            $name = \is_array($label) ? ($label['name'] ?? null) : $label;
            if (\is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
