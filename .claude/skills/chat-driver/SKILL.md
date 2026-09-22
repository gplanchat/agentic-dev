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
  `until` matches anywhere in what was printed since the chat opened, so pick a pattern that only
  the expected answer produces.
- A `run_command` blocks the screen for as long as the command runs (120 s at most): raise
  `timeout` accordingly.
