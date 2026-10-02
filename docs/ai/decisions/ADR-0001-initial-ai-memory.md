# ADR-0001: Initial AI Memory

## Status

Accepted

## Context

The repository needed durable, low-token AI memory so future ai-config sessions can understand the project boundaries, validation commands, and operational conventions without rediscovering them from scratch. The `ai-memory` script was run from the repository root and scaffolded `docs/ai/`.

The project already has root `AGENTS.md` guidance for repository layout, Composer workflow, PHP checks, native/package validation, and generated-file boundaries. Durable memory should complement that operational guidance rather than duplicate every detail.

## Decision

Use `docs/ai/` as routed AI memory:

- `docs/ai/README.md` remains the router and should be read first.
- `docs/ai/architecture.md` records repository scope, architectural boundaries, invariants, validation commands, style, and risk notes.
- `docs/ai/decisions/*.md` records durable decisions and tradeoffs.
- `docs/ai/logs/YYYY-MM.md` records short continuity notes by month.
- `docs/ai/plans/*.md` records approved implementation plans when planning work.

Keep memory concise and task-routed. Do not load all of `docs/ai/**` by default.

## Consequences

- Future agents can quickly find the project contract and validation commands before planning or implementation.
- Architecture changes and important workflow decisions should update the appropriate routed memory file.
- Product code, build configuration, and generated files remain governed by repository guidance and should not be changed during research-only memory maintenance.
