<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\Durable\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * The only place in the prototype that talks to the provider. Everything crosses the boundary as
 * raw arrays: `Contract::createRequestPayload()` has already normalised the conversation on the
 * workflow side, and the provider answers JSON. Nothing to map, hence nothing to make diverge.
 */
interface ModelInvocationActivityInterface
{
    /**
     * @param array<string|int, mixed> $payload
     * @param array<string, mixed>     $options
     *
     * @return array<string, mixed>
     */
    #[AsActivityMethod('ai_model_invoke')]
    public function invokeModel(string $model, array $payload, array $options): array;

    /**
     * The same call, under a different activity name.
     *
     * The provider cannot tell the difference, the **projection** can: it rebuilds the thread from
     * the last `ai_model_invoke`, and the payload of a compaction is the conversation that is about
     * to be replaced. Under the same name, a resume that stayed silent would redisplay the old
     * conversation as if it were the turn in progress.
     *
     * @param array<string|int, mixed> $payload
     * @param array<string, mixed>     $options
     *
     * @return array<string, mixed>
     */
    #[AsActivityMethod('ai_model_compact')]
    public function compactConversation(string $model, array $payload, array $options): array;
}
