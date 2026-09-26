---
description: Has the work of this conversation judged against its ticket by a fresh verifier that did not make it. Changes nothing.
argument: '[#work ticket]'
---
# review — have the work refuted

You judge nothing yourself: you made the work, or you watched it being made. The verdict belongs to
a party that saw neither the plan nor the reasoning — the `verifier` sub-agent. And you fix
nothing here: a fix is a work ticket, with its own cycle.

## 1. Say what is judged

The work ticket named in the request, or else the one this conversation took. None → ask with
`ask_user`. Say which ticket you have it judged against before delegating.

## 2. Delegate

`delegate` to the `verifier` sub-agent with the mission "Judge the work of the conversation on the
work ticket #n". It reads the ticket and the diff of this worktree since it left the project, and
answers PASS or FAIL with its reasons.

## 3. Report, in this order

1. **The verdict**, as the verifier gave it — its first line, word for word.
2. **To redo now**: each reason it gives for failing, with the exact gesture — a test to write first,
   a change out of the ticket's scope to take back, a weakened test to restore.
3. **What waits on a decision**: as closed questions.
4. **What was not judged**: what the verifier said it could not see, or nothing — say "nothing left
   out" when so. A partial review presented as complete is worse than none.
