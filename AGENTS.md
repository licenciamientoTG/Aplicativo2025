# Project Agent Instructions

This repository uses specialized-agent guidance. The primary Codex agent acts as
the orchestrator; it classifies work and delegates substantial implementation
when warranted.

- Roles: `.agents/roles/`
- Detailed instructions: `.agents/instructions/`
- Routing and proportional flow: `.agents/routing.md`
- Self-contained handoffs: `.agents/handoffs/TEMPLATE.md`

Default subagent context policy: use `fork_turns: "none"` when supported. Do not
create agents unnecessarily; see the routing policy.

Project-specific conventions are kept in the specialized instructions to keep
this entry point small.
