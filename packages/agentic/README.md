# gplanchat/agentic

Durable AI agents on Symfony AI: tool guard, human questions, watches, context budget and
delegation. A conversation is a durable workflow (`gplanchat/durable`); every message, approval or
answer is a signal, so a conversation survives a restart and resumes where it stopped.

- `Domain/`: the model, framework-free.
- `Application/`: ports and use cases (`Conversations`, `AgentTool`, `help`).
- `Infrastructure/`: the durable workflow and its Symfony AI 0.13 adapters (pinned).

For the Symfony integration, the TUI and its web version, see
[`gplanchat/agentic-bundle`](https://github.com/gplanchat/agentic-bundle).

```bash
composer require gplanchat/agentic
```

Requires PHP ≥ 8.2. `gplanchat/durable` has no stable release yet, so the application installing
this package needs `"minimum-stability": "dev"` (with `"prefer-stable": true`).

This repository is a read-only split of the
[`gplanchat/agentic-dev`](https://github.com/gplanchat/agentic-dev) monorepo
(`packages/agentic`). Issues and pull requests go there.

MIT license.
