# Insightistic project memory

This folder is the durable hand-off system for Insightistic. It is designed so a new AI agent can understand the project without guessing from old chat messages or unverified assumptions.

## Read order

1. Project root `claude.md`
2. This file
3. `current-status.md`
4. The newest dated work log under `work-log/`
5. The relevant reference file:
   - `architecture.md` for system boundaries and hosting
   - `operations.md` for testing, deployment, and incident procedures
   - `decisions.md` for decisions and reasons

## Folder structure

```text
Memory/
├── README.md                         # how the memory system works
├── current-status.md                 # replaceable current truth and open work
├── architecture.md                   # durable system and repository map
├── operations.md                     # safe runbooks and verification gates
├── decisions.md                      # durable decisions and rationale
├── templates/
│   └── session-update-template.md    # copy for each meaningful work session
└── work-log/
    └── YYYY-MM-DD/
        └── topic.md                  # append-only dated session records
```

## Status language

- **Verified:** directly checked in the current environment and dated.
- **Historical:** previously reported and retained for context; recheck before relying on it.
- **Pending:** work or verification still required.
- **Blocked:** a concrete dependency prevents progress.
- **Not performed:** deliberately not executed, usually because it would create an external side effect or needs owner input.

## Update rules

- Use Asia/Dhaka time for work-log timestamps.
- Keep `current-status.md` short enough to read at session start. Replace stale status instead of growing it forever.
- Keep dated work logs append-only. If a later session corrects an earlier fact, add a correction note rather than rewriting history.
- Update architecture/operations/decisions only when the durable project truth changes.
- Record source paths, commands, URLs, commits, image digests, and test counts when they are safe to disclose.
- Never record secrets, full environment values, private keys, access tokens, customer data, or raw authentication headers.
- Distinguish local verification from live verification and from an owner-reported result.

## Session close checklist

- [ ] Git status checked and unrelated work preserved.
- [ ] Tests/build/health checks recorded.
- [ ] Live changes and deployment evidence recorded, or explicitly marked not performed.
- [ ] `current-status.md` updated.
- [ ] Dated work log added.
- [ ] Relevant architecture/operations/decision record updated.
- [ ] Open risks and next action recorded.

