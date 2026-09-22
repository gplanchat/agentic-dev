<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Mcp;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A PSR-18 client whose response body is read as it comes, not buffered.
 *
 * This is what a remote MCP server needs. The Streamable HTTP transport answers a request with a
 * `text/event-stream` that **stays open**: the server pushes its replies as events and closes when
 * it decides to. Symfony's own `Psr18Client` reads the whole body before it returns a response, so
 * against such a server it never returns — the chat hung on `/mcp` with GitHub's endpoint.
 *
 * Here the body is a PHP stream over the response ({@see StreamWrapper}), so `read()` gives what has
 * arrived and the transport can cut its SSE events out of it, chunk by chunk.
 *
 * It is given a blocking client (curl) on purpose: the MCP transport runs its requests inside a
 * `\Fiber` of its own, with its own waiting loop, so an Amp client would suspend that fiber for a
 * Revolt loop that never runs there.
 */
final readonly class StreamingHttpClient implements ClientInterface
{
    private Psr17Factory $psr17;

    public function __construct(private HttpClientInterface $client)
    {
        $this->psr17 = new Psr17Factory();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = $values;
        }

        $response = $this->client->request($request->getMethod(), (string) $request->getUri(), [
            'headers' => $headers,
            'body' => (string) $request->getBody(),
            // The whole point: no buffering, and no exception on a 4xx/5xx — the transport reads the
            // status itself and says what happened.
            'buffer' => false,
        ]);

        // Asking for the status waits for the headers only; the body has not been read yet.
        $psr = $this->psr17->createResponse($response->getStatusCode());
        foreach ($response->getHeaders(false) as $name => $values) {
            foreach ($values as $value) {
                $psr = $psr->withAddedHeader($name, $value);
            }
        }

        return $psr->withBody(Stream::create(StreamWrapper::createResource($response, $this->client)));
    }
}
