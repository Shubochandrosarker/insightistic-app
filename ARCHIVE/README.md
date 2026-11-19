# Archive

This folder contains legacy and stale files that were moved out of the active repository root during the 2026-11-19 reorganization. They are preserved for historical reference but are no longer part of the production codebase or deployment pipeline.

## Contents

- **BUILD-STATUS.md** — Point-in-time build log from the original 6-week spec (September 2020). Explicitly marked as superseded by docs/roadmap-2026/00-BASELINE.md. Kept for history only.
- **docker/pay.insightistic.com/index.html** — Standalone Paddle checkout page for the pay.insightistic.com domain. Not referenced by any docker-compose, nginx, or CI/CD configuration in this repo; the live checkout page is hosted separately.
- **docs/RELEASE-2026-11-CONVERGENCE.md** — Release notes for the v2026.11.1 convergence milestone. Not referenced by any active documentation, code, or deployment configuration.

## Why Archived

These files were identified as legacy/stale during a repository cleanup:
- Superseded by newer documentation (BUILD-STATUS.md)
- Not referenced by production deployment configs (docker/pay.insightistic.com/)
- One-off release notes with no ongoing relevance (RELEASE-2026-11-CONVERGENCE.md)

All active production paths remain at the repository root: insightistic-api/, insightistic-app/, insightistic/, docker/, nginx/, .github/, scripts/, docs/, and the docker-compose files.
