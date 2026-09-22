<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\SymfonyAi;

use Gplanchat\Agentic\Infrastructure\Durable\Activity\ModelInvocationActivityInterface;
use Gplanchat\Durable\Attribute\AsActivityHandler;
use Symfony\AI\Platform\Bridge\Mistral\ModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelClientInterface;

/**
 * Runs the model call outside the workflow. Takes and returns arrays: the payload was normalised on
 * the workflow side by `Contract::createRequestPayload()`, the reply is the provider's JSON.
 */
#[AsActivityHandler(contract: ModelInvocationActivityInterface::class)]
final class ModelInvocationActivityHandler implements ModelInvocationActivityInterface
{
    public function __construct(
        private readonly ModelClientInterface $client,
        private readonly ModelCatalogInterface $catalog = new ModelCatalog(),
    ) {
    }

    public function invokeModel(string $model, array $payload, array $options): array
    {
        $raw = $this->client->request($this->catalog->getModel($model), $payload, $options);

        // The HTTP error belongs to the activity, not to the workflow code: a 429 or a 503 must
        // meet Durable's retry policy (DUR011), not be journaled as a success and then make the
        // converter choke on replay.
        $response = $raw->getObject();
        $data = $raw->getData();

        // A window overflow is not a failure: it is a reply. Replaying it would give the same
        // verdict, so it is journaled as data and it is the workflow code that compacts and then
        // asks again.
        if (ContextOverflow::detected($data)) {
            return $data;
        }

        if (method_exists($response, 'getStatusCode') && ($code = $response->getStatusCode()) >= 400) {
            throw new \RuntimeException(\sprintf('The provider replied %d: %s', $code, $response->getContent(false)));
        }

        return $data;
    }

    public function compactConversation(string $model, array $payload, array $options): array
    {
        return $this->invokeModel($model, $payload, $options);
    }
}
