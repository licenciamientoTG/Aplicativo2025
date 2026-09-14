# Routing and Proportional Flow

Classify first; use the smallest workflow that protects quality. Cases may be
mixed. Never spawn an agent merely because it exists.

| Area | Route to |
| --- | --- |
| Controllers, models, APIs, server validation, auth, authorization, business logic | Backend |
| Twig views, forms, CSS, jQuery, client state/validation, UI behavior | Frontend |
| SQL, schema, migrations, indexes, constraints, data migration | Database |
| Dependencies, multiple layers/steps, significant technical choices | Planner |
| Moderate/complex work; security, auth, migrations, meaningful business rules, public contracts, or multiple layers | Reviewer |

## Classification

| Class | Indicators | Default flow |
| --- | --- | --- |
| TRIVIAL | Isolated, few files, no business rule, architecture, or important contract change | Orchestrator → specialist |
| SMALL | Limited, clear, single-layer, low risk | Orchestrator → specialist → light verification |
| MODERATE | Multiple files/layers, business logic, API/UI interaction | Orchestrator → Planner → needed specialists → Reviewer |
| COMPLEX | Architecture, major schema/data migration, auth/security, cross-cutting or complex rules | Orchestrator → Planner → needed specialists → verification → Reviewer |

Run specialists in parallel only when their scopes and dependencies do not
overlap. The Reviewer must be independent from the implementing agent.

## Runtime policy

Use the available collaboration tool with a concise handoff and
`fork_turns: "none"` by default. This repository format does not configure
runtime agents, models, reasoning effort, or verbosity: select only values the
active Codex runtime exposes. Prefer stronger reasoning for orchestration,
planning, and review; medium reasoning for bounded implementation. Request
concise subagent outputs through the handoff rather than a non-existent
repository verbosity setting.
