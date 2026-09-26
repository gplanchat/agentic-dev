---
description: Frames a new need into the plan — a head ticket of the right family, its OpenSpec change in the repository when a specified behaviour moves, and its work tickets with their proofs and waits. Writes no production code.
argument: '<the need, in a sentence or a paragraph>'
---
# cadrer — from a need to work that can be taken

You write no production code. Every question you leave open becomes a session that turns in vain
later: ask it now with `ask_user`, or say plainly that it is left to the author.

The forge holds the plan (heads, work tickets, waits); the repository holds the specification.

## 1. Restate, and look for what exists

Restate the need in three lines — for whom, what, why now — and have it confirmed with `ask_user`.
Then call `ticket_list` and read the heads close to it: a need that overlaps an open head **extends
it** (new work tickets under it) rather than opening a second one.

## 2. Choose the family of the head

First answer wins: something behaves otherwise than written → `defect`; it pays back a past choice
→ `debt`; it prepares what comes, with no visible value today → `groundwork`; its result is to
know → `investigation` (then say its time box and the decision it must enable); otherwise →
`capability`. Never by size.

## 3. Does a specified behaviour move?

- **Yes** → an OpenSpec change in the repository, `openspec/changes/<name>/`: `proposal.md` (user
  stories with a priority — a P1 must be deliverable alone, and you name the test that proves it —,
  measurable success criteria that name no technology, and an **Out of scope** section that is never
  empty) and the spec delta `specs/<capability>/spec.md` under `## ADDED|MODIFIED|REMOVED
  Requirements`, each requirement with at least one `#### Scenario:`. No `tasks.md`: the tasks are
  work tickets. Write them with `edit_file`, and `commit_worktree` them.
- **No** (tooling, docs, a visual change) → no change files: the head's body carries the need.
- **You hesitate** → the need holds two: propose to split it.

An architecture decision nobody has taken is asked with `ask_user`, not written as if it were.

## 4. Open the head, then its work

1. `ticket_open_head` with the family, a title, and a body saying the need and, when there is one,
   the path of its change.
2. One `ticket_open_work` per task: a title starting with its id (`1.1 …`), a body saying what must
   be true once done and **how to prove it** — the test and its level. Order them the way they are
   done; test tasks before the code they prove; every `Scenario:` covered by a task.
3. `ticket_block` for every task that waits on another.

A capability always gets work tickets; a simpler head gets them only when more than one person must
share it.

## 5. Report

Five lines: the family and why, the change written or not and why, the counts (stories, scenarios,
work tickets), what already waits and on whom, and the first work ticket to take with `/continuer #n`.
