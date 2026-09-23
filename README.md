# agentic

Monorepo:

| Package | Role | PHP |
|---|---|---|
| `packages/agentic` — `gplanchat/agentic` | `Domain/`: the model (tool guard, questions, watches, context budget, delegation), framework-free. `Application/`: ports and use cases (`Conversations`, `AgentTool`, `help`). `Infrastructure/`: the durable workflow and its Symfony AI 0.13 adapters (pinned). Depends on `gplanchat/durable`. | ≥ 8.2 |
| `packages/agentic-bundle` — `gplanchat/agentic-bundle` | Symfony integration: the `agentic` TUI application (`help`, `chat`) and its web version. Depends on `gplanchat/durable-bundle` and `symfony/tui`. | ≥ 8.4.1 |
| root | Dev application installing both by `path`. | ≥ 8.4.1 |

The decisions the code cannot state on its own — why, and what was turned down — are in
[`docs/decisions/`](docs/decisions/README.md). Start with
[ADR-001](docs/decisions/ADR-001-value-objects-and-enums.md): value objects over arrays, enums over
magic strings, and where the journal boundary puts the limit on both.

```bash
composer install
bin/agentic                          # help in the TUI (q, Esc or Ctrl+C to quit)
bin/agentic chat                     # talk to the agent (Shift+Tab mode, ↑↓ history, wheel scrolls, Ctrl+X closes, Ctrl+C quits)
bin/agentic chat <conversation>      # resume a conversation (its id is printed when you quit)
php8.4 -S localhost:8000 -t public   # the web version: http://localhost:8000/agentic/
```

Tests, package by package (PHPUnit 11 for `agentic`, which must stay installable on PHP 8.2):

```bash
(cd packages/agentic && composer install && php8.2 vendor/bin/phpunit)
(cd packages/agentic-bundle && composer install && php8.4 vendor/bin/phpunit)
```

Each package splits its suite along the test pyramid (`static`, `unit`, `functional`,
`integration`; `--testsuite <layer>`); `tests/TestPyramidTest.php` fails when a test sits in no
layer or in two. No e2e yet.

The static layers run PHPUnit's static suite then PHPStan (level 8, `phpstan.dist.neon` per
package, a baseline for the errors the code already had: fixing one means deleting its entry).
New code meets the level — it does not go to the baseline.

The mutation layers run Infection (`tools/infection`, a tool project of its own: it needs PHP ≥ 8.3,
so it cannot be a dependency of `packages/agentic`, which stays installable on 8.2) over the lines
changed since HEAD, new files included. A surviving or uncovered mutant is RED, and the verdict asks
for a sharper test — never for other code. Two things follow from where Infection lives: it runs the
tests it mutates under PHP 8.4, so a green `component-mutation` says nothing about the 8.2 floor that
`component-unit` and `component-functional` check; and a worktree borrows `tools/*/vendor` read-only
through `sandbox.shared`, like any other installed dependency.

## The chat

A conversation is an execution of the `DurableAgentWorkflow` workflow; every message, approval,
answer or alert is a signal. The TUI acts as the worker: it drains Durable's Messenger transports on
every refresh, with no `messenger:consume` alongside.

- Without `MISTRAL_API_KEY`, a scripted client answers, with no network: "What is the weather in
  Paris?" (read-only tool), "Send a mail" (approval), "Ask me a question", "Watch the delivery"
  (watch), "Delegate…" (sub-agent). A trigger is matched as a whole word.
- With `MISTRAL_API_KEY` (in `.env.local`, ignored by git, or in the environment), Mistral answers;
  its replies are formatted (Markdown). The model call happens inside the TUI process, but through an
  Amp HTTP client on the same event loop: the screen stays alive while it waits — the banana dances,
  the counter runs, the thread can be scrolled.
- A tool that fails three times in a row is handed back to the model as its result ("Tool … failed");
  a failing model call ends the conversation, and the header says why.
- Chat commands, typed instead of a message (Tab completes the name):
  `/help`, `/mode [standard|edition|auto]`, `/model [name]` (from the next message on),
  `/tools` (and what the guard makes of each in the current mode), `/clear` (new conversation),
  `/rewind [n°]` (go back before one of your messages, which returns to the input), `/compact`
  (restart from a summary), `/resume [id]` (resume a past conversation).
  `/rewind`, `/compact` and resuming a finished conversation open a fresh conversation from the
  thread: the journal of the old one is never rewritten.
- The wheel and PgUp/PgDn scroll the thread inside the chat: the terminal switches to mouse mode for
  the duration. To select text with the mouse, hold Shift (most terminals).
- ↑/↓ recall the messages and commands already sent, like a shell.
- **Decision hooks** (`tool_rules` in `config/packages/agentic.php`): for a tool (an `fnmatch`
  pattern) and, where needed, conditions on its arguments, `allow`, `ask` or `deny`, before the mode.
  Deny wins over ask, which wins over allow; `/tools` shows the rules.
- **`run_command` in a sandbox** (`sandbox` in `config/packages/agentic.php`): bubblewrap mounts the
  project writable, `/usr` and `/etc` read-only, nothing else of the disk; no network, a cleared
  environment, `.env.local`, `.env.*.local` and `var/` hidden, `.git` and `.claude` read-only.
  **Limit:** whatever the agent writes into the project (`composer.json`, `vendor/bin/*`, a test
  bootstrap…) will run on the host when you launch it — review before you do. The screen freezes for
  the duration of a command (120 s at most). No shell: the line is split then executed as is (`;`,
  `|`, `$(…)` stay literal). Outside `auto`, every command asks for approval; in `auto`, only those
  of `sandbox.auto_allow` pass (`fnmatch` patterns over the whole line). If bwrap is missing or
  refused, the chat says so when it opens (`sudo apt install bubblewrap`). Rules also accept `modes`
  and `unless`:
  `['tool' => 'run_command', 'modes' => ['edition'], 'when' => ['command' => 'vendor/bin/phpunit*'], 'decision' => 'allow']`.
- **A worktree per conversation.** A conversation that runs a command works in
  `.worktrees/agentic-<id>`, on branch `agentic/agentic-<id>`, cut from HEAD — the project's
  uncommitted changes are not in it. The agent cannot commit from inside the sandbox (the project's
  `.git` is read-only there), so **all of its work sits uncommitted in the worktree** and `git diff`
  alone hides the files it created. Review with `git -C .worktrees/agentic-<id> status`, then
  `git -C .worktrees/agentic-<id> add -A && git -C .worktrees/agentic-<id> diff --cached`; take the
  work by committing in the worktree. Discard it with
  `git worktree remove .worktrees/agentic-<id> && git branch -D agentic/agentic-<id>`.
  **Do not run anything on the host inside a worktree** — `bin/console`, `bin/agentic`, `composer`,
  a test suite, an agent session — before you have reviewed it: ignored files the agent created are
  invisible to `git status`. The sandbox pre-creates and masks `var/`, `.claude/` and `.env.local` in
  each worktree, but not every `.env.*.local`.
  **Nothing is cleaned up automatically**, and that is deliberate — a worktree holds
  the agent's work until someone reviews it, and `/rewind`, `/compact` and `/resume` open new
  conversations that keep the same worktree, so tying removal to a conversation ending would destroy
  live work. A cleanup command may come later.
- **`run_checks(layer, filter?)`** runs one layer of the project checks — whatever `sandbox.checks`
  names: static, unit, functional, integration, e2e — in the same sandbox and workspace, then reads
  its JUnit report. It answers `GREEN — <layer>` or `RED — <layer>` with the counts and each failing
  test with its `file:line` and message (ten at most), instead of kilobytes of raw output;
  `ERROR — <layer>` when no report came out, with the tail of the output, and `RED` too when the
  exit code fails although no test did. Its arguments are closed — a configured layer, a filter
  passed as a single value to `filter_option` — so it needs no allowlist. Classed `write`: it asks
  in `standard`, passes in `edition` and `auto`. One timeout per layer (300 s by default). `tests` per layer says where its
  tests live; `review` per layer names the layers to run once it is green — the GREEN verdict spells
  them out, in order —, and an unknown name there is refused when the tool is built. With checks
  configured, the system prompt carries the pyramid and the TDD cycle
  (red → green → review), and each verdict ends with the next step. A layer that runs
  the bundle suite works from inside the sandbox, nested bwrap included.
- **Sub-agents** (`agents` in `config/packages/agentic.php`): named profiles the `delegate` tool can
  hand a mission to, each with its description (which the model reads to choose), its instructions,
  its model, the tools it may use (`fnmatch` patterns) and its ceiling. `/agents` lists them,
  `/agents <name>` shows one in full, instructions included. **A profile never grants authority**:
  the sub-agent takes the strictest of its ceiling and of its caller's effective mode, so a profile
  declared `auto` stays `standard` in a `standard` conversation — naming a sub-agent must not be the
  way around the guard. A name nobody declares is refused and the model is told which exist. The
  profiles are frozen in the conversation's payload at start, like its tools.
- **MCP servers** (`mcp.servers` in `config/packages/agentic.php`): their tools are offered to the
  agent as `mcp__<server>__<tool>`, over stdio (`command`, `args`, `env`, `cwd`) or HTTP (`url`,
  `headers`). `/mcp` lists the servers, says which are reached and shows their tools.
  **An MCP tool is `external` by default**, so it asks for approval in every mode but `auto`: what a
  server says about its own tools — `readOnlyHint` and the other annotations — is its claim about
  itself, and the guard is what protects from a tool that lies. Give effects explicitly with
  `effects` (a tool name pattern → `read`, `write` or `external`), which wins over everything else,
  or accept the server's word with `trust_annotations: true`. Discovery happens when a conversation
  starts and its schemas are frozen in the payload: a server that changes its tools afterwards does
  not change a running conversation, and a tool that disappeared comes back to the model as a failed
  result. A server that is down costs nothing — no tool, an error shown by `/mcp`, the conversation
  starts anyway. Tried against the official GitHub server, which offers 45 tools:

  ```php
  'mcp' => ['servers' => ['github' => [
      'command' => 'docker',
      'args' => ['run', '-i', '--rm', '-e', 'GITHUB_PERSONAL_ACCESS_TOKEN', 'ghcr.io/github/github-mcp-server', 'stdio'],
      'env' => ['GITHUB_PERSONAL_ACCESS_TOKEN' => '%env(GITHUB_TOKEN)%', 'PATH' => '%env(PATH)%', 'HOME' => '%env(HOME)%'],
      'effects' => ['get_*' => 'read', 'list_*' => 'read', 'search_*' => 'read'],
  ]]],
  ```

  A server reached over `url` works too — GitHub's remote endpoint answers the Streamable HTTP way,
  with a `text/event-stream` that stays open, and the client reads its events as they come. One
  difference with stdio: **an MCP call over HTTP freezes the screen for its duration**, because the
  MCP transport waits inside a fiber of its own rather than on the event loop the TUI runs on.
- **`read_file` and `edit_file`** work in the same sandbox and the same workspace as `run_command`
  (the conversation's worktree). `read_file` (`read`, passes in every mode) prints numbered lines,
  400 by default, with `offset`/`limit`, and says how to read on; on a directory it lists the
  entries. `edit_file` (`write`: asks in `standard`, passes in `edition` and `auto`) replaces an
  exact `old_string`, which must be unique unless `replace_all` is set; an empty `old_string`
  creates a file — and its directories — that must not exist yet. Both run as a small PHP script
  inside bwrap, so they see exactly what `run_command` sees. They refuse what resolves outside the
  workspace (`..`, absolute paths, symlinks), what is read-only (`.git`, `.claude`, the borrowed
  `vendor/`) and what is masked (`.env.local`, `var/`…), with a sentence rather than a silent
  success. Two things worth saying plainly: in `auto`, `edit_file` plus an allowlisted
  `vendor/bin/phpunit` runs whatever the agent just wrote, without approval — `auto_allow` limits
  which programs start, not what code they run, and the sandbox is the barrier; and `read_file`
  reads HEAD's version of the files, not your uncommitted edits, its first call being what cuts the
  worktree even in `standard`.
- **`AGENTS.md`** at the project root (path configurable through `instructions_file`): appended to
  the system prompt when a conversation starts, truncated beyond 32 KiB. It carries the code
  convention of [ADR-001](docs/decisions/ADR-001-value-objects-and-enums.md) and nothing the prompt
  already says — the pyramid and the TDD cycle come from `run_checks`, and paying for them twice
  would cost every turn of every conversation.
- **Journal on SQLite** (`var/agentic.sqlite`): conversations survive the TUI being closed and can be
  resumed. The Messenger transports stay in memory, the TUI being the only worker: a turn in flight
  when you quit is lost.
