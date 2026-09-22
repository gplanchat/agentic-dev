<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Ai;

use Gplanchat\Agentic\Infrastructure\SymfonyAi\ScriptedChatModelClient;
use Symfony\AI\Platform\Bridge\Mistral\Llm\ModelClient as MistralModelClient;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\Component\HttpClient\AmpHttpClient;

/**
 * Who actually answers for the model, on the activity side.
 *
 * Without a key, the scripted client returns deterministic answers and everything else — journal,
 * guard, counter, deadlines — behaves exactly the same. With a key, it is Mistral that speaks.
 *
 * **Amp client, not curl.** The model call happens in the interface process: a blocking client
 * would freeze the screen for as long as the answer takes. Amp runs on the same event loop as the
 * TUI ({@see \Revolt\EventLoop}): it suspends the current task, and the loop carries on — the
 * banana dances, the counter advances. Off the loop (web, `messenger:consume`), it behaves like an
 * ordinary client.
 *
 * ponytail: an `if` rather than a container compiler pass. The day there are three providers, it
 * will be a tag and a locator.
 */
final class ModelClientFactory
{
    private function __construct()
    {
    }

    public static function create(#[\SensitiveParameter] ?string $apiKey): ModelClientInterface
    {
        return '' === trim((string) $apiKey) ? new ScriptedChatModelClient() : new MistralModelClient(new AmpHttpClient(), $apiKey);
    }
}
