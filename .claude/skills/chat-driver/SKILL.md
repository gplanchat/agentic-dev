---
name: chat-driver
description: Launch and drive the agentic chat TUI (bin/agentic chat) headlessly to test it end to end — send messages and slash commands, approve tools, check what the agent did (tool calls, worktree edits). Use when asked to run, try or test the chat, a tool (read_file, edit_file, run_command, MCP), a mode or a slash command in the real app rather than in PHPUnit.
---

# Driving the chat in a test

`bin/agentic chat` is a full-screen TUI: it needs a terminal. `driver.py`, next to this file, gives it
a pseudo-terminal (140×50), types each step, waits for a pattern, prints what appeared, then quits
with Ctrl+C. No tmux needed, only Python 3 and `php8.4`.

```bash
python3 .claude/skills/chat-driver/driver.py '[
  {"send": "/mode edition", "until": "mode edition", "timeout": 15},
  {"send": "What is the weather in Paris?", "until": "Paris : ", "timeout": 60}
]'
```

Each step: `send` (typed, then Enter; `""` = Enter alone, which picks the first choice of a list —
"Approve" on an approval), `until` (a Python regex on the screen text, ANSI stripped), `timeout`
(seconds, default 60), `settle` (seconds to keep reading after the match, default 2 — raise it when
the agent chains several tools after the one you waited for). Exit code 1 if a step timed out. The
conversation id is printed at the end.

`{"click": "regex", "until": ...}` clicks the lowest row of the screen matching the regex — an
edit's diff unfolds on `"click": "click to unfold"`, folds back on `"click": "▴ fold"` — then prints
the whole screen as it stands, rebuilt from the renderer's cursor moves.

`{"keys": ["down", "enter"], ...}` presses keys (`up`, `down`, `enter`, `shift+tab`, `esc`).
`{"choose": "regex", ...}` walks a list (a questionnaire) down to the first row matching the regex,
then presses Enter; with no match, it takes the first row. Any step takes `"pause": seconds` before
it.

## Recording the README demo

`--record FILE` writes an asciicast v2, typing at a human pace and stopping before the exit;
`--size COLSxROWS` sets the terminal. `docs/demo/agentic.gif` comes from `docs/demo/steps.json`,
with the real model (ask first), then [agg](https://github.com/asciinema/agg):

```bash
python3 .claude/skills/chat-driver/driver.py --real-model --size 120x40 --record demo.cast "$(cat docs/demo/steps.json)"
agg --font-size 14 --idle-time-limit 2 --last-frame-duration 8 demo.cast docs/demo/agentic.gif
```

The model answers differently each take: check the answers picked in the output, and take again.

## Launch directory and approval

The agent works on the project it is launched from. `--cwd DIR` launches it there (default: this
repository). A project's `.agentic/config.{yaml,yml,toml,json,xml}` — this repository has one — is
used only once approved: new or changed, it is shown before the chat opens and the chat asks
`Use it?`. `--before y` (or `n`) answers that question, then the driver waits for the chat as usual.
Without `--before`, an unapproved file keeps the chat from opening and the driver reports it did not
open. Approvals are recorded in `var/agentic-trust.json` of the installation.

To test another project without leaving this repository, build it under `var/` as a git repository
of its own (`git init` inside it): anywhere else in this repository, the worktree would be this
repository's.

## Scripted client or real model

- **Default: scripted client, no network.** The driver forces `MISTRAL_API_KEY=` empty, because
  `.env.local` carries a real key. The scripted client answers only its triggers, matched as whole
  words: "What is the weather in Paris?" (read-only tool), "Send a mail" (approval), "Ask me a
  question", "Watch the delivery" (watch), "Delegate…" (sub-agent). It never calls `read_file`,
  `edit_file` or `run_command`.
- **`--real-model`: Mistral, with the key of `.env.local`.** Only when the test needs a model that
  picks tools itself — the workspace tools above. It costs API calls: **ask the user first**, every
  time. Name the tool in the message ("Use read_file to…"), and allow 60–180 s per step.

## What to check, and where

- **Modes.** `standard` asks before `edit_file` and `run_command`; send `/mode edition` first, or
  answer the approval with `{"send": "", ...}`. In `auto`, `run_command` passes only for
  `sandbox.auto_allow`.
- **Tool calls** show as `⚙ <tool> {arguments} → <result>` lines.
- **Workspace tools act in the conversation's worktree**, `.worktrees/agentic-<first 8 of the id>`,
  on branch `agentic/agentic-<same>`, cut from `HEAD` on the first call. Check the effect there, and
  that the project itself did not move:

  ```bash
  git worktree list
  git -C .worktrees/agentic-<id> status --short
  git status --short
  ```

## Clean up

Every conversation that used a workspace tool leaves a worktree and a branch. Remove the ones your
test created — only those:

```bash
git worktree remove --force .worktrees/agentic-<id> && git branch -D agentic/agentic-<id>
```

The conversation itself stays in the journal (`var/agentic.sqlite`); `/resume` lists it.

## Known limits

- The screen text is a stream of redraws, not a snapshot: the same line can appear several times.
  `until` matches anywhere in what the step printed — the header's `your turn` included, redrawn
  while you type: wait for an answer with `thinking…[\s\S]*your turn`.
- A `run_command` blocks the screen for as long as the command runs (120 s at most): raise
  `timeout` accordingly.
