<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Mcp;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Mcp\Client;
use Mcp\Client\Builder;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Tool;
use Psr\Log\LoggerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\CurlHttpClient;

/**
 * The tools of the configured MCP servers, offered to the agent like any other.
 *
 * **Discovery happens outside the workflow**, when a conversation starts: the schemas are frozen in
 * its payload and replayed from there. A server that changes its tools afterwards does not change a
 * running conversation — the journal wins.
 *
 * **A server that is down costs a conversation nothing.** Discovery is bounded by a timeout and
 * every failure is caught: the server contributes no tool, and its error is what `/mcp` shows.
 * Failing to start the chat because an `npx` is missing would be the wrong trade.
 *
 * @implements \IteratorAggregate<int, AgentTool>
 */
final class McpCatalog implements \IteratorAggregate
{
    /** @var array<string, Client>|null connected clients, one per server, for this process */
    private ?array $clients = null;

    /** @var array<string, string> server name → why it could not be reached */
    private array $failures = [];

    /** @var list<AgentTool>|null */
    private ?array $tools = null;

    /**
     * @param list<McpServer> $servers
     */
    public function __construct(
        private readonly array $servers = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->discover());
    }

    /**
     * What `/mcp` tells: one line per declared server, reached or not.
     *
     * @return list<array{server: string, transport: string, connected: bool, tools: int, error: string|null}>
     */
    public function status(): array
    {
        $this->discover();

        $status = [];
        foreach ($this->servers as $server) {
            $status[] = [
                'server' => $server->name,
                'transport' => null !== $server->command ? 'stdio' : 'http',
                'connected' => isset($this->clients[$server->name]),
                'tools' => \count(array_filter(
                    $this->tools ?? [],
                    static fn (AgentTool $tool): bool => str_starts_with($tool->definition()->name, 'mcp__'.$server->name.'__'),
                )),
                'error' => $this->failures[$server->name] ?? null,
            ];
        }

        return $status;
    }

    /**
     * Runs a tool of a server. Called from an activity, never from workflow code.
     *
     * A failure comes back as text rather than as an exception: the model reads it as the tool
     * result and adapts. Throwing would cost three retries of a call that cannot succeed, then the
     * conversation.
     *
     * @param array<string, mixed> $arguments
     */
    public function call(string $server, string $tool, array $arguments): string
    {
        $this->discover();

        $client = $this->clients[$server] ?? null;
        if (null === $client) {
            return \sprintf('The MCP server "%s" is unreachable: %s', $server, $this->failures[$server] ?? 'not declared.');
        }

        try {
            $result = $client->callTool($tool, $arguments);
        } catch (\Throwable $failure) {
            $this->logger?->warning('MCP call failed: {message}', ['message' => $failure->getMessage(), 'server' => $server, 'tool' => $tool]);

            return \sprintf('The MCP tool "%s" of "%s" failed: %s', $tool, $server, $failure->getMessage());
        }

        $text = [];
        foreach ($result->content as $content) {
            $text[] = $content instanceof TextContent ? $content->text : json_encode($content, \JSON_UNESCAPED_UNICODE);
        }
        $answer = trim(implode("\n", array_filter($text, static fn (?string $line): bool => null !== $line && '' !== $line)));

        if ($result->isError) {
            return \sprintf('The MCP tool "%s" of "%s" answered with an error: %s', $tool, $server, '' === $answer ? 'no detail' : $answer);
        }

        return '' === $answer ? '(the tool answered nothing)' : $answer;
    }

    /**
     * @return list<AgentTool>
     */
    private function discover(): array
    {
        if (null !== $this->tools) {
            return $this->tools;
        }

        $this->clients = [];
        $this->tools = [];

        foreach ($this->servers as $server) {
            try {
                $client = $this->connect($server);
                foreach ($this->listTools($client) as $tool) {
                    $this->tools[] = new McpTool($this, $server, $tool);
                }
                $this->clients[$server->name] = $client;
            } catch (\Throwable $failure) {
                // One server down is not a conversation down.
                $this->failures[$server->name] = $failure->getMessage();
                $this->logger?->warning('MCP server "{server}" unreachable: {message}', ['server' => $server->name, 'message' => $failure->getMessage()]);
            }
        }

        return $this->tools;
    }

    private function connect(McpServer $server): Client
    {
        $client = (new Builder())
            ->setClientInfo('agentic', '0.1')
            ->setInitTimeout($server->timeoutSeconds)
            ->setRequestTimeout($server->timeoutSeconds)
            ->build();

        if (null !== $server->command) {
            $client->connect(new StdioTransport($server->command, $server->args, $server->cwd, [] === $server->env ? null : $server->env));

            return $client;
        }

        // The PSR-18 client is ours rather than discovered, for two reasons.
        //
        // Streaming: a remote MCP server answers with an event-stream that stays open, which a
        // buffering client never finishes reading ({@see StreamingHttpClient}).
        //
        // Blocking: curl, not Amp, unlike the model call. The SDK's HTTP transport runs its request
        // inside a `\Fiber` of its own and waits in a loop of its own; an Amp client suspends that
        // fiber waiting for the Revolt loop, which never runs there — the request never returns.
        // The price is the screen freezing for the duration of an MCP call over HTTP; a stdio server
        // does not pay it.
        $psr17 = new Psr17Factory();
        $client->connect(new HttpTransport(
            (string) $server->url,
            $server->headers,
            new StreamingHttpClient(new CurlHttpClient()),
            $psr17,
            $psr17,
        ));

        return $client;
    }

    /**
     * @return list<Tool>
     */
    private function listTools(Client $client): array
    {
        $tools = [];
        $cursor = null;
        do {
            $page = $client->listTools($cursor);
            foreach ($page->tools as $tool) {
                $tools[] = $tool;
            }
            $cursor = $page->nextCursor ?? null;
        } while (null !== $cursor);

        return $tools;
    }
}
