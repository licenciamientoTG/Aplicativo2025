# TotalGas Agent Architecture

The primary Codex agent is the orchestrator. It classifies each request and
uses only the specialists that add value: Planner for meaningful design work;
Backend, Frontend, or Database for scoped implementation; and an independent
Reviewer for moderate or complex risk.

Routing and the TRIVIAL, SMALL, MODERATE, and COMPLEX flows are in
[`routing.md`](routing.md). Each specialist's responsibility and boundary are
in `roles/`; reusable detailed rules are in `instructions/`.

Every delegation uses the small self-contained handoff in
[`handoffs/TEMPLATE.md`](handoffs/TEMPLATE.md), normally with
`fork_turns: "none"`. Add a new specialist by creating a short role file,
adding detailed instructions only when unique rules are needed, and adding its
routing criteria. Do not duplicate shared rules.

Codex runtime options are not declared by this repository. When creating a
subagent, choose only models and reasoning levels exposed by that runtime;
prefer strong reasoning for decisions/review and medium for bounded execution.
Ask for concise output in the handoff. No repository-wide verbosity setting is
used.
