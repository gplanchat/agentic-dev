<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\SymfonyAi;

/**
 * Recognising a window overflow in what the provider replied.
 *
 * This is not a transport failure, it is a **reply**: "your payload is too big". Replaying it as is
 * will give the same verdict, so Durable's retry policy has no business here — it is up to the
 * workflow code to change the payload and ask again. Hence the separate handling in
 * {@see \Gplanchat\Agentic\Infrastructure\SymfonyAi\ModelInvocationActivityHandler}, which throws on every other error but
 * journals this one as data.
 *
 * The codes come from the Mistral bridge, which recognises them the same way to throw its
 * `ExceedContextSizeException`.
 *
 * @see \Symfony\AI\Platform\Bridge\Mistral\Llm\ResultConverter
 */
final class ContextOverflow
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data body of the provider's reply
     */
    public static function detected(array $data): bool
    {
        $code = $data['error']['code'] ?? $data['code'] ?? null;
        if ('context_length_exceeded' === $code) {
            return true;
        }

        $message = (string) ($data['error']['message'] ?? $data['message'] ?? '');

        return '' !== $message
            && (str_contains($message, 'maximum context length') || str_contains($message, 'context_length_exceeded'));
    }
}
