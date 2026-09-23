# ADR-001: Value Objects over arrays, enums over magic strings

**Date:** 2026-09-23
**Status:** Accepted
**Deciders:** @gplanchat
**Informed:** anyone writing in this repository — humans, and the agent this repository builds

---

## Context

The code already works this way. `AgentMode`, `ToolEffect` and `ToolVerdict` are backed enums;
`Principal`, `ToolRule`, `ToolInvocation`, `ToolDefinition`, `Toolset`, `AgentProfile`, `Watch`,
`TranscriptMessage` and a dozen others are readonly value objects. `tests/ArchitectureTest.php`
already forbids the domain from importing a framework. What does not exist is the sentence saying
*why*, and a convention nobody wrote down is a convention that erodes at the first hurry.

It erodes faster here than in most repositories, for a reason particular to this one: **an agent
writes code in it.** `run_command` and `edit_file` work in a per-conversation worktree, and what
steers that agent is the system prompt plus `AGENTS.md` — not the taste it would have absorbed by
reading a few hundred files. A convention that lives only in the existing code is a convention the
agent restates by accident at best.

The decision is not free, and that is what makes it worth recording rather than assuming. This
project has a hard constraint pulling the other way: **the workflow payload is the journal.**
`DurableAgentWorkflow::run()` takes `array $tools`, `array $toolRules`, `array $agents`,
`array $owner`. Those arrays are serialized, stored, and replayed — possibly weeks later, possibly
by a newer deployment of the code. The journal is append-only; a payload written on Monday must
still be readable by Friday's classes. So "value objects everywhere" cannot be the whole rule, and
the interesting part of this decision is where the boundary sits.

Two recent incidents sharpen it. PHPStan, added to the static layer, found that `ChatView` read
`$watch->intention` after the property had been renamed `intent` — the TUI crashed with a
`TypeError` the moment any watch was armed. And `DurableToolExecutor::delegate()` carries a standing
warning: Durable matches child-workflow arguments **by position**, so a parameter inserted into the
middle of `run()` silently shifts every one after it and each falls back to its default, with no
exception and no trace.

---

## Decision

**We will model every domain concept as a value object or a backed enum, and confine arrays and
strings to the journal boundary, crossed only by a `fromWire()` / `toWire()` pair.**

The rule has three parts, and the third is what makes the first two survivable:

1. **Inside the domain and the application layer**, a concept is a type. A mode is `AgentMode`, not
   `'auto'`. An effect is `ToolEffect`, not `'external'`. An owner is `Principal`, not
   `['id' => …, 'roles' => […]]`.
2. **At the journal boundary**, data is plain arrays and scalars. The workflow payload, the activity
   arguments and the signal payloads are wire format, because they must outlive the classes that
   wrote them.
3. **The crossing is named and it is the only one.** `fromWire()` builds the type and validates;
   `toWire()` flattens it. Nothing between the two handles the raw shape — `Toolset::fromWire()` on
   entry, and no code below it has ever seen an associative array of tool definitions.

The core insight is that the boundary is where **validation** belongs, and a type is what makes the
validation unforgettable. `ToolRule::fromWire()` throws on an unknown mode, and its comment says
exactly why: *"filtered out silently, it would leave the rule holding in every mode — harmless for a
refusal, but an opening for an approval."* A magic string that nobody parses is a magic string
nobody checks; a typo in a security rule then widens it instead of breaking it. That is the argument
in one line: **with strings, a mistake degrades quietly in the permissive direction.**

---

## Consequences

### Positive

- **Mistakes become loud.** When the `owner` parameter was deliberately moved out of last position
  to test for it, PHP answered `Argument #20 ($owner) must be of type array, null given` rather than
  handing the sub-agent an empty identity. A positional wire with untyped slots would have passed.
- **`match` becomes exhaustive.** `AgentMode::requiresApprovalFor()` states the whole
  read/write/external table in one place and cannot forget a case. Adding a fourth mode makes every
  `match` over it a compile-time concern.
- **Behaviour has somewhere to live.** `AgentMode::strictest()` and `Principal::restrictedTo()` are
  the two places that keep authority from growing by delegation. On arrays, those would be helper
  functions any caller could forget to call — which is precisely how the guard becomes decoration.
- **PHPStan can see it.** Level 8 over typed properties catches renames like `intention` → `intent`.
  It cannot catch the same mistake through `$watch['intention']`.
- **The agent has a rule it can read**, rather than a taste it would have to infer.

### Negative

- **Every domain type carries a `fromWire()`/`toWire()` pair.** That is real boilerplate, and it is
  the price of the journal, not an accident of style.
- **Two representations must stay in agreement.** A field added to a value object and forgotten in
  `toWire()` is silently absent from the journal.
- **The wire shape is now a compatibility surface.** `ToolRule::toWire()` and `AgentProfile::toWire()`
  run their optional keys through `array_filter` for exactly this reason: a rule written before
  `unless_roles` existed must keep the shape it had. Adding a field means thinking about payloads
  already on disk.
- **PHPDoc array shapes lie, and we must not trust them.** Declaring `roles?: list<string>` on
  journal data claimed a guarantee the wire cannot give; the honest annotation is `array<mixed>`
  with the normalisation kept. A shape annotation is a claim, not a check.

### Risks

- **The forgotten `toWire()` is the live failure mode.** Mitigation: every value object that crosses
  the journal gets a round-trip test — `PrincipalTest::testItSurvivesTheJournalBothWays()` is the
  shape to copy, and `testAnOwnerWithNoRoleKeepsTheShapeItWasWrittenWith()` the compatibility half.
- **The rule can be over-applied.** A value object wrapping a single string with no behaviour and no
  validation is ceremony. The test is whether it validates something, carries behaviour, or names a
  closed vocabulary — `Principal` earns it (`is()`, `restrictedTo()`, a non-empty id); a bare
  `ConversationId` wrapping a UUID would not.
- **This assumes the domain stays framework-free**, which `ArchitectureTest` enforces today. If that
  test is ever relaxed, `Principal` gains a path to becoming `UserInterface` and the boundary
  dissolves.

---

## Alternatives Considered

### Option A: Value objects all the way, including the workflow payload

**Description:** `run()` would take `Toolset $tools, list<ToolRule> $toolRules, Principal $owner`,
and Durable would serialize and deserialize them.

**Why rejected:**
It couples the journal format to the shape of a PHP class. A payload written today must be replayed
by whatever the code becomes; a constructor signature that changes turns old runs into failures
rather than into data a `fromWire()` can adapt. The append-only journal is not a cache that can be
dropped. Durable's positional matching for child workflows makes it worse: a class shape offers no
place to put the tolerance that `fromWire()` gives for free.

**What it would have been good for:**
A system whose serialized state is short-lived — a queue message consumed within the minute, or any
store that can be migrated in a single pass. The whole difficulty here comes from durability.

### Option B: Arrays everywhere, with PHPStan array shapes for safety

**Description:** Keep the wire shape throughout and let `array{mode: string, roles: list<string>}`
annotations carry the typing, backed by level 8.

**Why rejected:**
This session disproved it empirically. A shape annotation is erased at runtime — and PHPStan trusted
mine (`roles?: list<string>`) enough to declare a defensive `array_values()` redundant, on data
arriving from a journal that guarantees no such thing. The analyser was reasoning from a claim, not
from a check. There is also nowhere to hang behaviour: `strictest()` and `restrictedTo()` would
become free functions, and the security property they enforce would depend on every caller
remembering to call them.

**What it would have been good for:**
A codebase with no serialization boundary and no security-relevant invariants, where the array shape
is the whole truth and static analysis is the only reader that matters.

### Option C: Class constants instead of enums

**Description:** `final class AgentMode { public const AUTO = 'auto'; … }`.

**Why rejected:**
It gets the autocompletion and loses everything else. `match` over constants is not exhaustive, so a
fourth mode is a silent gap rather than an error. There is no `tryFrom()`, so the boundary check has
to be hand-written at each entry point — and `ToolRule::fromWire()` throwing on an unknown mode is
exactly the check that would be forgotten somewhere.

**What it would have been good for:**
PHP before 8.1. Not a live consideration: the packages require 8.2 and 8.4.

---

## Links

- [ADR index](./README.md)
- `packages/agentic/tests/ArchitectureTest.php` — the domain imports no framework
- `packages/agentic/src/Domain/Guard/ToolRule.php` — `fromWire()` throwing on an unknown mode, and
  the comment explaining why silence would be an opening
- `packages/agentic/src/Domain/Identity/Principal.php` — `restrictedTo()`, and the round-trip tests
  that guard the wire shape
- `packages/agentic/src/Infrastructure/SymfonyAi/DurableToolExecutor.php` — the positional
  child-workflow call, and `DelegateNarrowsIdentityTest` which fails when its slots shift
