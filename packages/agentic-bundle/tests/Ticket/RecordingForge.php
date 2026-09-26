<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Ticket;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A forge that answers the given responses in turn, and records what it was asked:
 * `METHOD url body` per request, headers apart.
 */
final class RecordingForge
{
    /** @var list<string> */
    public array $requests = [];

    /** @var list<list<string>> */
    public array $headers = [];

    public readonly MockHttpClient $http;

    public function __construct(MockResponse ...$responses)
    {
        $this->http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = rtrim(\sprintf('%s %s %s', $method, $url, (string) ($options['body'] ?? '')));
            $this->headers[] = $options['headers'] ?? [];

            return array_shift($responses) ?? throw new \LogicException(\sprintf('No response left for %s %s.', $method, $url));
        });
    }
}
