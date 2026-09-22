<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Ai;

use Gplanchat\Agentic\Infrastructure\SymfonyAi\ScriptedChatModelClient;
use Symfony\AI\Platform\Bridge\Mistral\Llm\ModelClient as MistralModelClient;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Qui répond réellement au modèle, côté activité.
 *
 * Sans clé, le client scripté rend des réponses déterministes et tout le reste — journal, garde,
 * guichet, échéances — se comporte exactement pareil. Avec une clé, c'est Mistral qui parle.
 *
 * ponytail: un `if` plutôt qu'un compilateur de conteneur. Le jour où il y a trois fournisseurs,
 * ce sera un tag et un locator.
 */
final class ModelClientFactory
{
    private function __construct()
    {
    }

    public static function create(#[\SensitiveParameter] ?string $apiKey): ModelClientInterface
    {
        return '' === trim((string) $apiKey) ? new ScriptedChatModelClient() : new MistralModelClient(HttpClient::create(), $apiKey);
    }
}
