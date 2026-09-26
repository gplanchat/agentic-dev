<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Ticket;

use Gplanchat\Agentic\Application\Ticket\Tickets;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Where a project keeps its tickets, as its `.agentic/config.*` says. The token is not here: it
 * belongs to the installation, never to a file the agent's project carries.
 */
final readonly class TicketTracker
{
    /**
     * @param string      $repository `owner/name`
     * @param string|null $url        the forge's root; GitHub: its API, `https://api.github.com` by default
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public Forge $forge,
        public string $repository,
        public ?string $url = null,
        public HeadLabels $labels = new HeadLabels(),
    ) {
        if (1 !== preg_match('#^[\w.-]+/[\w.-]+$#', $repository)) {
            throw new \InvalidArgumentException(\sprintf('The ticket repository is "owner/name", not "%s".', $repository));
        }
        if (Forge::Forgejo === $forge && (null === $url || '' === $url)) {
            throw new \InvalidArgumentException('A Forgejo ticket tracker needs the url of the forge.');
        }
    }

    public function tickets(HttpClientInterface $http, string $token): Tickets
    {
        return match ($this->forge) {
            Forge::GitHub => new GitHubTickets($http, $this->repository, $token, $this->labels, $this->url ?? 'https://api.github.com'),
            Forge::Forgejo => new ForgejoTickets($http, (string) $this->url, $this->repository, $token, $this->labels),
        };
    }
}
