<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Mcp;

use Gplanchat\AgenticBundle\Mcp\StreamingHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\Process\Process;

/**
 * Against a local endpoint that answers the way a remote MCP server does: an event-stream that
 * stays open between two events.
 */
final class StreamingHttpClientTest extends TestCase
{
    private static Process $server;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        self::$port = random_int(8200, 8999);
        self::$server = new Process(['php', '-S', '127.0.0.1:'.self::$port, '-t', __DIR__.'/fixtures']);
        self::$server->start();
        usleep(500_000);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    /**
     * The point of the class: the response comes back before the stream ends, and reading it gives
     * what has arrived. A buffering client returns only once the server closes — which a real MCP
     * server does not do until the session ends.
     */
    public function testTheResponseComesBackBeforeTheStreamEnds(): void
    {
        $started = microtime(true);
        $response = (new StreamingHttpClient(new CurlHttpClient()))->sendRequest(
            (new Psr17Factory())->createRequest('GET', 'http://127.0.0.1:'.self::$port.'/sse.php'),
        );
        $headersAfter = microtime(true) - $started;

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/event-stream', $response->getHeaderLine('content-type'));
        self::assertLessThan(0.6, $headersAfter, 'The headers must arrive before the second event, 0.7 s later.');

        $first = $response->getBody()->read(4096);
        self::assertStringContainsString('"result":"first"', $first);
        self::assertStringNotContainsString('"result":"second"', $first, 'The second event has not been sent yet.');

        $rest = '';
        while (!$response->getBody()->eof()) {
            $rest .= $response->getBody()->read(4096);
        }
        self::assertStringContainsString('"result":"second"', $rest);
        self::assertGreaterThan(0.7, microtime(true) - $started);
    }
}
