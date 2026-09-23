How code is written here. These are not preferences: each rule below is load-bearing, and the
reasoning — with the alternatives that were turned down — is in
`docs/decisions/ADR-001-value-objects-and-enums.md`. Read it before arguing with one of them.

## Types, not shapes

A concept in `Domain/` or `Application/` is a value object or a backed enum. Never a magic string,
never a bare array.

- A mode is `AgentMode`, not `'auto'`. An effect is `ToolEffect`. An owner is `Principal`.
- Behaviour lives on the type: `AgentMode::strictest()`, `Principal::restrictedTo()`,
  `ToolRule::matches()`. A free function every caller has to remember is how a guard becomes
  decoration.
- Keep `match` over an enum exhaustive. Adding a case then tells you every place that must change,
  instead of falling through a `default` nobody revisits.
- `Domain/` imports no framework and no runtime — `tests/ArchitectureTest.php` enforces it. The
  domain speaks its own vocabulary; adapters translate at the edge.

## Arrays only at the journal boundary

The workflow payload, the activity arguments and the signal payloads are wire format: plain arrays
and scalars. They are that way because they are **journaled** — written today, replayed weeks later
by a newer deployment. They must outlive the classes that wrote them.

- Cross the boundary with `fromWire()` / `toWire()`, and nowhere else. Below `Toolset::fromWire()`,
  nothing handles a raw array of tool definitions.
- **Validate in `fromWire()`, and throw.** `ToolRule::fromWire()` refuses an unknown mode, and the
  reason generalises: a mode dropped silently would leave the rule holding in *every* mode —
  harmless on a refusal, an opening on an approval. With strings, a mistake degrades quietly in the
  permissive direction. That is the whole argument.
- A new optional field leaves `toWire()` only when it says something (see the `array_filter` in
  `ToolRule::toWire()`), so a payload written before that field existed keeps the shape it had. The
  journal is append-only: you cannot go back and fix old rows.
- Every type that crosses the journal gets a round-trip test. Copy
  `PrincipalTest::testItSurvivesTheJournalBothWays()`, and its compatibility half
  `testAnOwnerWithNoRoleKeepsTheShapeItWasWrittenWith()`.

## PHPDoc shapes are claims, not checks

`array{roles: list<string>}` on data arriving from the journal promises what the wire cannot
guarantee. Annotate what is true — `array<mixed>` — and keep the normalisation. Never delete a
defensive `array_values()` or cast because PHPStan called it redundant on the strength of your own
annotation: fix the annotation instead.

## When a value object is the wrong answer

One that wraps a single string, validates nothing and carries no behaviour is ceremony. The test:
does it validate something, carry behaviour, or name a closed vocabulary? `Principal` earns it — a
non-empty id, `is()`, `restrictedTo()`. A bare `ConversationId` around a UUID would not.

## Adding a parameter to the durable workflow

`DurableAgentWorkflow::run()` is called **positionally** by the child-workflow stub in
`DurableToolExecutor::delegate()`. A parameter inserted in the middle shifts every one after it, and
each silently falls back to its default — no exception, no trace. Add new parameters **last**, add
them last in that positional call too, and make sure a test fails when the slots shift
(`DelegateNarrowsIdentityTest` is the one that does).
