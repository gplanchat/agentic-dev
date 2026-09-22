# agentic

Monorepo :

| Paquet | Rôle | PHP |
|---|---|---|
| `packages/agentic` — `gplanchat/agentic` | `Domain/` : le modèle (garde des outils, questions, veilles, budget de contexte, délégation), sans framework. `Application/` : ports et cas d'usage (`Conversations`, `AgentTool`, `help`). `Infrastructure/` : le workflow durable et ses adaptateurs Symfony AI 0.13 (épinglé). Dépend de `gplanchat/durable`. | ≥ 8.2 |
| `packages/agentic-bundle` — `gplanchat/agentic-bundle` | Intégration Symfony : l'application TUI `agentic` (`help`, `chat`) et sa version web. Dépend de `gplanchat/durable-bundle` et `symfony/tui`. | ≥ 8.4.1 |
| racine | Application de dev qui installe les deux par `path`. | ≥ 8.4.1 |

```bash
composer install
bin/agentic                          # l'aide en TUI (q, Échap ou Ctrl+C pour quitter)
bin/agentic chat                     # discuter avec l'agent (Shift+Tab mode, ↑↓ historique, molette défilement, Ctrl+X clore, Ctrl+C quitter)
php8.4 -S localhost:8000 -t public   # la version web : http://localhost:8000/agentic/
```

Tests, paquet par paquet (PHPUnit 11 pour `agentic`, qui doit rester installable en PHP 8.2) :

```bash
(cd packages/agentic && composer install && php8.2 vendor/bin/phpunit)
(cd packages/agentic-bundle && composer install && php8.4 vendor/bin/phpunit)
```

## Le chat

Une conversation est une exécution du workflow `DurableAgentWorkflow` ; chaque message, validation,
réponse ou alerte est un signal. La TUI fait office de worker : elle vide les transports Messenger
de Durable à chaque rafraîchissement, sans `messenger:consume` à côté.

- Sans `MISTRAL_API_KEY`, un client scripté répond, sans réseau : « Quel temps fait-il à Paris ? »
  (outil en lecture), « Envoie un mail » (validation), « Pose-moi une question », « Surveille la
  livraison » (veille), « Délègue… » (sous-agent).
- Avec `MISTRAL_API_KEY` (dans `.env.local`, ignoré par git, ou dans l'environnement), c'est Mistral. L'écran se fige le temps de chaque réponse : l'appel
  modèle est une activité exécutée dans le processus de la TUI.
- Un outil qui échoue trois fois de suite est rendu au modèle comme résultat (« Échec de l'outil… ») ;
  un appel modèle qui échoue clôt la conversation, et l'en-tête dit pourquoi.
- Commandes du chat, tapées à la place d'un message (Tab complète le nom) :
  `/help`, `/mode [standard|edition|auto]`, `/model [nom]` (à partir du message suivant),
  `/tools` (et ce que la garde en fait dans le mode courant), `/clear` (nouvelle conversation).
- La molette et Pg.Préc/Pg.Suiv font défiler le fil dans le chat : le terminal passe en mode souris
  le temps du chat. Pour sélectionner du texte à la souris, maintenir Maj (la plupart des terminaux).
- ↑/↓ rappellent les messages et commandes déjà envoyés, comme dans un shell.
- **Hooks de décision** (`tool_rules` dans `config/packages/agentic.php`) : pour un outil (motif
  `fnmatch`) et, au besoin, des conditions sur ses arguments, `allow`, `ask` ou `deny`, avant le mode.
  Le refus l'emporte sur la demande, qui l'emporte sur l'accord ; `/tools` affiche les règles.
- **`AGENTS.md`** à la racine du projet (chemin réglable par `instructions_file`) : ajouté au prompt
  système au démarrage de chaque conversation, tronqué au-delà de 32 Kio.
- **Journal en mémoire** : la conversation meurt avec la TUI. La faire survivre demande le backend
  DBAL (SQLite) et un transport Messenger durable.
