# Insightistic durable decisions

## 2026-11-17 — Dedicated project root

**Decision:** The active local project root is `D:\All Projects\WPISTIC-GALAXY-ECOSYSTEM\INSIGHTISTIC`.

**Reason:** The owner is organizing all WordPressistic ecosystem projects under one dedicated parent directory. A stable root avoids split work between the former C: drive checkout and the ecosystem archive.

**Implementation:** The former repository was moved with Git metadata and working-tree changes preserved. Pre-existing destination contents were retained under `_archive/pre-migration-destination-20261117` for recovery/reference.

**Follow-up:** Do not use the retired C: path for new work. The empty retired directory should be removed once the active Codex process releases its working-directory handle.

## 2026-11-17 — Memory-first agent workflow

**Decision:** `claude.md` is the canonical agent manual. `AGENTS.md` points agents to it. The `Memory/` folder contains current status, architecture, operations, decisions, templates, and dated work logs.

**Reason:** Future agents need durable, categorized context rather than guessing from chat history or mixing historical claims with verified live state.

**Update contract:** Every meaningful session updates `Memory/current-status.md` and adds an append-only dated work log. Durable architecture, operations, and decisions are updated only when their truth changes.

## 2026-11-17 — Separate public site and SaaS infrastructure

**Decision:** Keep the public marketing WordPress site on business hosting and the app/API on the VPS, with separate domains and deployment workflows.

**Reason:** This preserves operational isolation, supports independent scaling and release cycles, and matches the intended SaaS architecture.

## 2026-11-17 — Proxy-first frontend API connection

**Decision:** Prefer Next.js same-origin proxy mode for browser API calls.

**Reason:** It reduces CORS, mixed-content, and build-time API URL failures while keeping API routing server-controlled. Direct API mode remains an intentional exception documented in the repository.

## 2026-11-17 — Evidence labels are mandatory

**Decision:** Project memory must label facts as Verified, Historical, Pending, Blocked, or Not performed.

**Reason:** Production hosting, DNS, billing, PageSpeed, OAuth, and deployment state can drift. A prior report is useful context but is not proof of current behavior.

