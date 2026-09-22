# agentic

Monorepo :

| Paquet | Rôle | PHP |
|---|---|---|
| `packages/agentic` — `gplanchat/agentic` | Le modèle (garde des outils, questions, veilles, budget de contexte, délégation) et le cas d'usage `help`. Dépend de `gplanchat/durable`, d'aucun framework. | ≥ 8.2 |
| `packages/agentic-bundle` — `gplanchat/agentic-bundle` | Intégration Symfony : l'application TUI `agentic` et sa version web. Dépend de `gplanchat/durable-bundle` et `symfony/tui`. | ≥ 8.4.1 |
| racine | Application de dev qui installe les deux par `path`. | ≥ 8.4.1 |

```bash
composer install
bin/agentic                          # l'aide en TUI (q, Échap ou Ctrl+C pour quitter)
php8.4 -S localhost:8000 -t public   # la version web : http://localhost:8000/agentic/
```

Tests, paquet par paquet (PHPUnit 11 pour `agentic`, qui doit rester installable en PHP 8.2) :

```bash
(cd packages/agentic && composer install && php8.2 vendor/bin/phpunit)
(cd packages/agentic-bundle && composer install && php8.4 vendor/bin/phpunit)
```
