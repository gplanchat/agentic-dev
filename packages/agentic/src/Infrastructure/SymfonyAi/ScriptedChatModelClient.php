<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\SymfonyAi;

use Gplanchat\Agentic\Application\Chat\ToolCallRef;
use Gplanchat\Agentic\Domain\Question\AskUserQuestion;
use Gplanchat\Agentic\Domain\Team\DelegateTool;
use Gplanchat\Agentic\Domain\Watch\WatchTool;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;

/**
 * Answers like a "chat completions" provider, with no network and no API key.
 *
 * The demo does not need a real model to show what it shows: the journal, the activities, the guard
 * and the resume after a signal. A real provider is plugged in by replacing this service with the
 * `ModelClientInterface` of a `symfony/ai-*-platform` bridge.
 *
 * The reply is a pure function of the conversation received — hence deterministic, hence replayable.
 */
final class ScriptedChatModelClient implements ModelClientInterface
{
    public function supports(Model $model): bool
    {
        return true;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        $messages = $payload['messages'] ?? [];

        if ($this->isCompactionRequest($messages)) {
            return new InMemoryRawResult($this->text($this->digest($messages)));
        }

        $lastUser = '';
        $answeredSinceUser = 0;

        foreach ($messages as $message) {
            if ('user' === ($message['role'] ?? null)) {
                $lastUser = mb_strtolower((string) $message['content']);
                $answeredSinceUser = 0;
            } elseif ('tool' === ($message['role'] ?? null)) {
                ++$answeredSinceUser;
            }
        }

        // A single tool turn per message: on the second pass, we answer.
        if ($answeredSinceUser > 0) {
            return new InMemoryRawResult($this->text(
                $this->summarise($messages),
                'The tool has answered; I hand back its reading as is rather than paraphrasing it.',
            ));
        }

        // A vague request: the model does not guess, it asks. That is what a real model does when
        // the instruction leaves several equally reasonable continuations.
        if (self::mentions($lastUser, 'import')) {
            return new InMemoryRawResult($this->toolCall($messages, AskUserQuestion::TOOL, [
                'question' => 'How do you want to run this import?',
                'header' => 'Import mode',
                'options' => [
                    ['label' => 'Batch', 'description' => 'All at once, faster, locks the catalogue'],
                    ['label' => 'Streaming', 'description' => 'Slower, the catalogue stays served'],
                    ['label' => 'Dry run', 'description' => 'Nothing is written, we look at what would change'],
                ],
            ]));
        }

        $subject = self::firstWatchSubject($options);
        if (null !== $subject && (self::mentions($lastUser, 'watch', 'notify'))) {
            return new InMemoryRawResult($this->toolCall($messages, WatchTool::TOOL, [
                // The subject is what makes the watch reachable: it is the subject, not some free
                // text, that a business event will match.
                'subject' => $subject,
                'observation' => 'The supplier delivery arrives at the warehouse',
                'intent' => 'Record a goods receipt note and warn the team',
                'deadlineSeconds' => 900,
            ]));
        }

        // The explicit lever: a real model decides on its own to ask, a scripted model needs to be
        // told. "ask me…" is there to see the questionnaire at will, and "multiple" to see the other
        // shape.
        if (self::mentions($lastUser, 'ask', 'question')) {
            return new InMemoryRawResult($this->toolCall($messages, AskUserQuestion::TOOL, [
                'question' => 'What do you want me to decide on?',
                'header' => 'Up to you',
                'multiSelect' => self::mentions($lastUser, 'multiple'),
                'options' => [
                    ['label' => 'The weather', 'description' => 'I look it up, nobody has anything to approve'],
                    ['label' => 'A note', 'description' => 'I write into the current folder'],
                    ['label' => 'An email', 'description' => 'External effect: the guard will ask for your approval'],
                ],
            ]));
        }

        if (self::mentions($lastUser, 'mail', 'email')) {
            return new InMemoryRawResult($this->toolCall($messages, 'send_email', [
                'to' => 'team@example.test',
                'body' => 'Report requested from the durable chat.',
            ]));
        }

        // The lever of delegation: a scripted model does not decide on its own to hand over a task.
        if (self::mentions($lastUser, 'delegate', 'team')) {
            $agent = self::firstEnumValue($options, DelegateTool::TOOL, 'agent');

            return new InMemoryRawResult($this->toolCall($messages, DelegateTool::TOOL, null === $agent
                // A sub-agent the application declares, when there is one: that is what a real model
                // would do, since the schema offers the names.
                ? ['mission' => 'Sum up in one sentence what a durable agent does.', 'model' => 'ministral-3b-latest']
                // A mission the sub-agent can actually carry out with a tool of its own: the demo is
                // worth more when the delegate does something than when it answers about itself.
                : ['mission' => 'What is the weather in Lyon?', 'agent' => $agent]));
        }

        if (self::mentions($lastUser, 'note')) {
            return new InMemoryRawResult($this->toolCall($messages, 'save_note', ['text' => $lastUser]));
        }

        foreach (['paris', 'lyon', 'marseille'] as $city) {
            if (self::mentions($lastUser, $city)) {
                return new InMemoryRawResult($this->toolCall($messages, 'weather', ['city' => ucfirst($city)]));
            }
        }

        return new InMemoryRawResult($this->text(
            'I know how to look up the weather of a city, save a note, or send an email. Which one?'
        ));
    }

    /**
     * A trigger is a whole word: `ask` must not fire inside "task", nor `import` inside
     * "important". A demo whose branch depends on a substring is a demo that surprises.
     */
    private static function mentions(string $text, string ...$words): bool
    {
        foreach ($words as $word) {
            if (1 === preg_match('/\b'.preg_quote($word, '/').'\b/u', $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first watch subject the application publishes, read from the schema of the tool as it goes
     * out to the provider. The vocabulary belongs to the application, not to this client.
     *
     * @param array<string, mixed> $options
     */
    private static function firstWatchSubject(array $options): ?string
    {
        return self::firstEnumValue($options, WatchTool::TOOL, 'subject');
    }

    /**
     * The first value a tool's schema offers for one of its arguments — the vocabulary belongs to
     * the application, not to this client.
     *
     * @param array<string, mixed> $options
     */
    private static function firstEnumValue(array $options, string $tool, string $argument): ?string
    {
        foreach ($options['tools'] ?? [] as $candidate) {
            if (\is_array($candidate) && $tool === ($candidate['function']['name'] ?? null)) {
                $enum = $candidate['function']['parameters']['properties'][$argument]['enum'] ?? [];

                return \is_array($enum) && [] !== $enum ? (string) reset($enum) : null;
            }
        }

        return null;
    }

    /**
     * Compaction comes through the same door as the rest — it is its system instruction that tells
     * it apart, as it would at a real provider.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function isCompactionRequest(array $messages): bool
    {
        foreach ($messages as $message) {
            if ('system' === ($message['role'] ?? null)
                && str_contains((string) $message['content'], 'Summarise it')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A fake summary, but one that tells the truth: what was asked for, and where the conversation
     * had got to. A pure function of the conversation received, hence replayable.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function digest(array $messages): string
    {
        $asked = [];
        $lastAnswer = '';
        foreach ($messages as $message) {
            $role = $message['role'] ?? null;
            if ('user' === $role) {
                $asked[] = trim((string) $message['content']);
            } elseif ('assistant' === $role && null !== ($message['content'] ?? null)) {
                $lastAnswer = trim((string) $message['content']);
            }
        }

        if ([] === $asked) {
            return 'The previous conversation did not get past the introductions.';
        }

        return \sprintf(
            'The person had asked: %s. Last answer given: "%s". Nothing was left pending.',
            implode(', ', array_map(static fn (string $q): string => '"' . $q . '"', $asked)),
            '' === $lastAnswer ? 'none' : $lastAnswer,
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function summarise(array $messages): string
    {
        $last = '';
        foreach ($messages as $message) {
            if ('tool' === ($message['role'] ?? null)) {
                $last = (string) $message['content'];
            }
        }

        return '' === $last ? 'Done.' : $last;
    }

    /**
     * The call identifier derives from the rank of the turn: two builds of the same conversation
     * give the same identifier, otherwise the replay would diverge.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $arguments
     *
     * @return array<string, mixed>
     */
    private function toolCall(array $messages, string $tool, array $arguments): array
    {
        return ChatCompletion::ofToolCall(
            new ToolCallRef(\sprintf('call_%d', \count($messages)), $tool, $arguments),
        )->toWire();
    }

    /**
     * The text, with its reasoning if there is one. The shape of both lives in
     * {@see ChatCompletion::toWire()} — the same one the projection reads back.
     *
     * @return array<string, mixed>
     */
    private function text(string $text, string $reasoning = ''): array
    {
        return ChatCompletion::ofText($text, '' === $reasoning ? null : $reasoning)->toWire();
    }
}
