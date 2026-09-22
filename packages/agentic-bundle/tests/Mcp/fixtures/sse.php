<?php

declare(strict_types=1);

// An endpoint that answers like a Streamable HTTP MCP server: an event straight away, another one
// later, and the stream stays open in between.
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

echo "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":\"first\"}\n\n";
@ob_flush();
flush();

usleep(700_000);

echo "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":2,\"result\":\"second\"}\n\n";
@ob_flush();
flush();
