# Repository migration and memory system

**Date:** 2026-11-17  
**Time zone:** Asia/Dhaka  
**Status:** Completed locally; one OS cleanup follow-up remains

## Objective

Move the complete local Insightistic repository from `C:\Users\Qbits\insightistic-app` into the dedicated ecosystem project folder, preserve existing work, clean the retired source location, and establish a memory-first agent workflow.

## Work performed

- Inspected source and destination before moving.
- Confirmed the source was a Git repository with intentional modified and untracked files; no reset or cleanup was performed.
- Preserved the pre-existing destination contents under `_archive/pre-migration-destination-20261117`.
- Moved the source repository with Git metadata and file timestamps preserved using Robocopy with junction traversal disabled.
- Verified the new root contains `.git`, `insightistic-app/package.json`, `insightistic-api/artisan`, Docker, Nginx, plugin, docs, and the original user work.
- Removed the leftover `insightistic-api/public/storage` reparse-point link from the retired source path without following its target.
- Removed the now-empty `insightistic-api` and `public` directories from the retired path.
- Added `claude.md`, `AGENTS.md`, and the structured `Memory/` files.

## Repository evidence

- New Git root: `D:\All Projects\WPISTIC-GALAXY-ECOSYSTEM\INSIGHTISTIC`
- Branch at verification: `main...origin/main [behind 43]`
- Existing modified/untracked work remained visible in `git status`.
- No commit, reset, checkout, or broad cleanup was performed.

## Retired source path

- **Verified:** No project files remain under `C:\Users\Qbits\insightistic-app`; only the empty root directory remained during the final check.
- **Pending:** Windows refused to remove that empty root because the active Codex process was started with it as its working directory. Remove only that empty directory after the process releases the handle. Do not delete or move any other `C:\Users\Qbits` content.

## Memory system created

- `claude.md` — canonical agent operating manual.
- `AGENTS.md` — short agent entry point.
- `Memory/README.md` — read order, structure, status language, and update contract.
- `Memory/current-status.md` — current project state and open items.
- `Memory/architecture.md` — app/API/WordPress/hosting boundaries.
- `Memory/operations.md` — safe testing, deployment, WordPress, performance, and incident gates.
- `Memory/decisions.md` — durable decisions and rationale.
- `Memory/templates/session-update-template.md` — required close-out template.

## Next action

Open the project from the new D: drive root for future work. Recheck live app/API/WordPress status before the next production change, then update `current-status.md` and add a new dated log.

