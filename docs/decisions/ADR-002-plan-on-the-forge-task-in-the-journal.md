# ADR-002: The plan lives on the forge, the task in the journal, and the maker never judges itself

**Date:** 2026-09-26
**Status:** Accepted
**Deciders:** @gplanchat
**Informed:** anyone writing in this repository — humans, and the agent this repository builds

---

## Context

The agent could read, edit and check code in its own worktree; it had no notion of *work*. Two
needs arrived together, and the first design confused them:

- **Project management** — planning work that lasts weeks, costed and traced. The team's convention
  is Archibald's EWA-002: a **head** ticket is what matters to whoever pays (a capability, a defect,
  a debt, groundwork, an investigation); a **work** ticket is the leaf one person finishes in a day,
  with its proof, and the only place time is logged. The chain request → head → work must hold, and
  code attaches to the leaf. The team's own flavour of OpenSpec (the Épopée skills) already frames
  needs into changes and tasks.
- **Conducting one task** — the Mikado method: try the change naively, note what the failure shows
  must change first, revert, work on a leaf, climb back to the goal.

The first cut put the Mikado graph *on the forge*: one ticket per discovered prerequisite, linked
by "blocked by". It was wrong. A prerequisite found at 3 p.m. and done at 4 p.m. is not a leaf with
time and proof; it is a thought. Made a ticket, it floods the plan with debris EWA-002 forbids, and
bills a decision of the agent as a unit of work.

Four constraints of this repository shaped everything after that:

- **The journal is replayed.** Durable compares every replayed activity's payload with the one on
  record. Anything added to every model call — a built-in tool — breaks the replay of every
  conversation begun before it.
- **The sandbox's git directory is read-only**, so the agent cannot commit, nor reset its worktree,
  from inside. Mikado needs both: a revert that keeps finished leaves only works if leaves are
  committed.
- **Git run on the host inside a tree the agent writes is an escape hatch.** A relative
  `core.hooksPath` (Husky's), an fsmonitor command, a diff driver or a textconv chosen by
  `.gitattributes` all resolve inside that tree. This was reproduced — and was already open in
  `WorkingTreeChanges`.
- **Ticket bodies are written by strangers.** Whatever reads them reads instructions it must not
  follow — Linas's *agentic OS* makes this a standing threat, not an edge case.

---

## Decision

**We keep the plan on the forge and the conduct of a task in the conversation's journal; we let the
agent commit only on its own branch, through host-side git that runs nothing it wrote; and we give
the work to skills whose reading and judging happen in read-only seats, never in the seat that
made the work.**

1. **The forge is the source of truth of the plan**, behind the `Tickets` port (GitHub, Forgejo).
   A head carries its family as a label named in the project's words (`tickets.labels`); a work
   ticket hangs under an open head — a sub-issue on GitHub, a strict `Head: #n` line plus a
   dependency on Forgejo, which has no sub-issues. `Backlog` enforces what EWA-002 makes decidable:
   an investigation states its time box and the decision it enables, a head closes only over closed
   work, a work ticket closes with its code (`Closes #n`). What waits carries a label (`taken`,
   `waits:author`, `waits:third-party`, `waits:measure`); none is ever created by the agent. The
   specification stays in the repository, as OpenSpec changes: the forge holds the plan, git the
   behaviour.
2. **The Mikado graph is conversation state.** The `mikado_*` tools run inside the workflow, change
   a board the run owns, and journal each new state as a side effect — the board is rebuilt from
   what the journal hands back, never left to depend on the closure having run. It is carried by the
   relay, `/resume`, `/compact` and `/rewind` (its *latest* state: it describes the worktree, which is
   not rewound either). It is behind a Durable change point, `mikado-tools`: a conversation begun
   before it replays without it.
3. **The agent commits, on its own branch only.** `commit_worktree`, `revert_worktree` and
   `worktree_diff` run git on the host, in the conversation's worktree and nowhere else, commits as
   the author `agentic`, always with hooks and fsmonitor off — and `worktree_diff` with no external
   diff driver nor textconv. The human still decides what reaches the main branch.
4. **Skills are procedures, not built-in tools.** `/status`, `/scope`, `/continue`, `/review` send a
   short request as the user's message; the `skill` tool — whose description is the index — hands the
   procedure when it is needed. Nothing is added to every conversation's payload.
5. **Seats enforce the separation of powers.** The `planner` reads the tickets strangers wrote with
   only `ticket_list` and `ticket_read`, at the `plan` ceiling; the `verifier` judges a ticket
   against the diff with `ticket_read`, `worktree_diff` and `read_file`, at the same ceiling, and
   is never the conversation that did the work. `continue` commits each TDD phase so that the
   verifier can check the red test came first.

The core insight: **each kind of state goes where its lifetime and its readers are.** The plan
outlives conversations and is read by people who pay — the forge. A task's graph lives as long as
the task and is read by the model conducting it — the journal. The behaviour outlives both and is
reviewed with the code — the repository.

---

## Consequences

### Positive

- **The forge stays readable by the people it serves.** No debris of intermediate thoughts; heads
  sum their leaves (EWA-002 § 8); the closing is the platform's, at the merge.
- **A finished Mikado leaf survives the next revert**, because it is committed — which is what makes
  the method usable by an agent at all.
- **Old conversations keep replaying.** Proven on a journal recorded before the tools, whose marked
  twin diverges.
- **The escape through host-side git is closed** for every tool that runs git in the worktree,
  including the one that predated this work.
- **"Done" is not the maker's word.** The verifier sees neither the plan nor the reasoning.

### Negative

- **Épopée's own skills read `tasks.md`**; here the tasks are tickets. Nothing projects one into the
  other, and `/pm:statut` outside this repository no longer sees the plan these skills keep.
- **Labels must be created on the forge** before use: the families and the four marks.
- **`planner` and `verifier` appear in every conversation**, even one without a tracker or a
  sandbox, where their tools do not exist.
- **`ticket_list` costs one API call per open head and per candidate**, within a page of 100.
- **Forgejo's parent link is text**, editable by anyone; it is parsed strictly and refused when
  ambiguous, but it is text.
- **Carrying the latest graph on `/rewind` is a choice**, not an obvious truth: the thread goes back,
  the graph does not.

### Risks

- **`continue` still reads its own ticket** in the working conversation, where writes are within
  reach. The defences there are the rule that a ticket is data, the worktree, and the guard — not a
  read-only seat. Mitigation: keep the reading that does not need to write in a seat, as `status`
  does.
- **The procedures have not yet run with a real model against a real forge.** The tests prove the
  commands send their requests and the tools behave; they do not prove a model follows a procedure.
- **Autonomy is not earned yet.** There is no trust ledger per skill, no standing goals, no
  unattended loop. Starting a loop before the ledger would contradict the model this follows
  (autonomy per skill, from evidence).

---

## Alternatives Considered

### Option A: The Mikado graph as tickets

**Description:** One ticket per prerequisite, linked by "blocked by" — the first version, built and
then withdrawn.

**Why rejected:**
EWA-002 wants leaves that carry time and a proof of completion; a Mikado node carries neither. The
plan filled with debris, and the agent's thinking became units of billed work. The forge is also the
wrong reader: nobody but the conducting model needs the graph while it grows.

**What it would have been good for:**
A task so large that several people conduct its graph at once — at which point its branches are work
tickets, and that is exactly what `ticket_open_work` offers from inside a graph.

### Option B: `tasks.md` as the source of truth, the forge as a projection

**Description:** Keep Épopée's files authoritative and mirror them onto the forge in one direction.

**Why rejected:**
The decision was the forge's, taken deliberately: the plan is read and moved by people outside the
repository, and EWA-002's traceability lives on the platform that closes tickets at the merge.

**What it would have been good for:**
Keeping `/pm:statut` and the other Épopée skills working unchanged — the price of this decision.

### Option C: The graph in an ignored file of the worktree

**Description:** A `.mikado.md` next to the code, readable by the human.

**Why rejected:**
It is outside the journal, so it does not replay, and `revert_worktree` would have to spare it. The
journal already carries the conversation from run to run; the graph belongs with it.

### Option D: No agent commits; a human commits each green leaf

**Description:** Keep the former rule that the agent's work stays uncommitted.

**Why rejected:**
It turns the Mikado loop into a relay race with a human at every leaf, and a revert between two
human commits destroys finished work. The branch is the agent's; what reaches the main one stays the
human's call.

### Option E: Skills as built-in tools, or procedures pasted into the thread

**Description:** Offer each skill as an always-available tool, or have a command paste its procedure
as the message.

**Why rejected:**
A built-in tool enters every model call's payload and breaks the replay of every conversation in
flight — the Mikado tools needed a change point for exactly this reason. A pasted procedure fills
the thread with pages the human did not write. A short request plus progressive disclosure costs
nothing until the skill is used.

**What it would have been good for:**
Skills that must be available before any request — none of these four.

### Option F: Skills as project files from the start

**Description:** `.agentic/skills/*.md`, approved like the project's configuration.

**Why rejected (for now):**
These skills are built against this application's tools and are tested with them. A skill grants
tools, so a project's own skills need the approval path the configuration has; that is a later step,
not a reason to hold these back.

---

## Links

- [ADR index](./README.md) · [ADR-001](./ADR-001-value-objects-and-enums.md) — the wire rules the
  graph and the payloads follow
- Archibald, `docs/corporate/wa/EWA-002-tickets-tete-travail-et-chaine-de-tracabilite.md` — heads,
  work tickets, the traceability chain
- Linas, *How to Build an Agentic OS with Claude Fable 5* (2026-07-10) — separation of powers,
  per-skill trust, data is not instructions
- `packages/agentic/src/Application/Ticket/Backlog.php` — the decidable rules of EWA-002
- `packages/agentic/src/Domain/Mikado/` and `tests/Infrastructure/MikadoWorkflowTest.php` — the
  graph, its side effects, and the replay of a journal from before the change point
- `packages/agentic-bundle/src/Sandbox/HostGit.php` — git on the host, running nothing the agent
  wrote
- `packages/agentic-bundle/skills/`, `packages/agentic-bundle/seats/` — the procedures and the seats
