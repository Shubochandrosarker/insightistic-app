# C: drive Insightistic workspace audit

**Date:** 2026-11-17  
**Time zone:** Asia/Dhaka  
**Status:** Completed with conservative cleanup

## Objective

Audit `C:\Users\Qbits` for Insightistic-related folders and files after the canonical app repository moved to the ecosystem project root. Remove only clearly redundant or empty items while preserving active source, documentation, credentials/configuration, assets, and rollback material.

## Preserved important items

- `C:\Users\Qbits\insightistic` — active WordPress plugin repository with a feature branch and local agent records.
- `C:\Users\Qbits\insightistic-website` — active marketing-site repository with local modifications; must not be deleted.
- `C:\Users\Qbits\Insightistic-Project-Docs` — product vision, architecture, data model, security, testing, deployment, billing, and launch documentation.
- `C:\Users\Qbits\insightistic-deploy-backups` — deployment backup material.
- `C:\Users\Qbits\insightistic-deploy-20261104`, `insightistic-ai-pause-prod`, and `insightistic-stripe-review` — historical deployment/review snapshots retained for rollback or investigation.
- `C:\Users\Qbits\insightistic-443-release-check`, `insightistic-plugin-release`, and `insightistic-release-4.6.3` — plugin release/review snapshots retained because they contain distinct Git histories, release branches, or packages.
- `C:\Users\Qbits\insightistic-redesign` and both animation asset folders — theme recovery and reusable product/demo assets.
- `C:\Users\Qbits\.wpistic`, `.wpistic.zip`, and `.wpistic-deploy` contents where present — shared WordPressistic workspace/configuration or potentially sensitive deployment material. The empty `.wpistic-deploy` directory itself was removed.
- Release archives such as `insightistic-1c81d13.tgz` and `insightistic-*-20261104.tar.gz` — retained as rollback evidence.

## Removed

- `C:\Users\Qbits\insightistic-website-inspect` — clean, older Git snapshot at commit `eb33e513973b63a90b5efaba9c6e42fe00ef4ac8`. It was an ancestor of the active website repository at commit `a1b4157d645b5f41f5af40f6d500587feb2e8a14`, with no deleted files in the active repository relative to the snapshot.
- `C:\Users\Qbits\.wpistic-deploy` — empty directory.

## Not removed

- `C:\Users\Qbits\insightistic-app` — empty retired directory remains because Windows reports it is in use by the active Codex process. No project files remain inside it. Remove only this empty directory after the process releases the working-directory handle.

## Verification

- Active website repository had 41 local modifications before the cleanup and was preserved.
- Plugin repository had untracked `.mimosa` agent records and was preserved.
- No source, release, backup, configuration, credential, or documentation folder was deleted.

## Next action

Revisit the retained release/deployment snapshots only when a specific rollback or archive policy is agreed. Do not bulk-delete them by age or name.

