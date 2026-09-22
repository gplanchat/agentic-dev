<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Chat;

use Gplanchat\Agentic\Domain\Guard\AgentMode;

/**
 * Port : ce qu'une interface — terminal ou web — peut faire d'une conversation avec un agent.
 *
 * Une conversation est une exécution de workflow ; chaque intention ci-dessous devient un signal,
 * donc est journalisée et rejouée. Rien n'est attendu ici : l'interface relit {@see transcript()}.
 */
interface Conversations
{
    /**
     * @return string l'identifiant de la conversation
     */
    public function start(): string;

    public function send(string $conversation, string $text): void;

    public function decide(string $conversation, string $callId, bool $approved): void;

    /**
     * @param list<string> $answers
     */
    public function answer(string $conversation, string $callId, array $answers): void;

    public function alert(string $conversation, string $callId, string $observation): void;

    public function setMode(string $conversation, AgentMode $mode): void;

    /**
     * @throws \InvalidArgumentException si le modèle n'est pas connu
     */
    public function setModel(string $conversation, string $model): void;

    /**
     * Les modèles qu'une conversation peut prendre : ceux qui savent appeler des outils.
     *
     * @return list<string>
     */
    public function models(): array;

    public function close(string $conversation): void;

    /**
     * Le journal connaît-il cette conversation ? Un identifiant inconnu doit être refusé, pas ouvert
     * sur un écran vide qui ne répondra jamais.
     */
    public function exists(string $conversation): bool;

    /**
     * Les conversations les plus récentes d'abord.
     *
     * @return list<ConversationSummary>
     */
    public function recent(int $limit = 20): array;

    /**
     * Une conversation neuve qui repart du fil d'une autre — c'est ainsi que se reprend une
     * conversation terminée, qu'on revient en arrière, ou qu'on compacte. Le journal de l'ancienne
     * n'est jamais réécrit ; elle est close si elle tournait encore.
     *
     * @param int|null $keepUserMessages ne garder du fil que ce qui précède ce message humain (0 = le premier) ; `null` = tout
     * @param bool     $compact          résumer le fil repris avant le premier tour
     *
     * @return string la nouvelle conversation
     */
    public function restart(string $from, ?int $keepUserMessages = null, bool $compact = false): string;

    public function transcript(string $conversation): Transcript;
}
