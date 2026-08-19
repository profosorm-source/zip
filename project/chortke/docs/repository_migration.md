# Repository Migration Summary

Date: 2026-05-31

Summary:
- Removed thin repository abstractions for `Seo` and `Lottery` and migrated their responsibilities into domain Services.
- `SeoRepository` logic moved into `app/Services/Seo/AdsSeoService.php` (data helpers + higher-level operations).
- `LotteryRepository` usage migrated into `app/Services/Lottery/LotteryService.php` by injecting models (`LotteryRound`, `LotteryParticipation`, `LotteryDailyNumber`, `LotteryVote`, `LotteryChanceLog`) and `Core\Database`.
- Deleted files: `app/Repositories/SeoRepository.php`, `app/Repositories/LotteryRepository.php`.

Rationale:
- Project convention elsewhere uses models and lightweight services; the two repositories were thin wrappers adding file bloat and divergence from the codebase style.
- Moving small, reusable data operations into Services (or models) keeps domain logic centralized and reduces duplicate or unnecessary layers.

What changed (high level):
- Jobs and services that used `SeoRepository` now use `AdsSeoService` (methods: `getAd`, `getAdForUpdate`, `createExecution`, `executionExistsToday`, `countUserExecutionsToday`, `countUserExecutionsLastHour`, `countIpExecutionsLastHour`, `updateExecutionStatus`, `rejectExecution`, `completeExecution`, `markExecutionAsFraud`, `getUser`).
- `LotteryService` now directly uses injected models to perform reads/writes that were previously delegated to `LotteryRepository`.

Testing checklist (manual):
1. Start an SEO task (API/UI) — ensure session created and response `success`.
2. Run through SEO task complete path (or fire `ProcessSeoTaskAsyncJob`) — ensure payouts, fraud checks, and referrals trigger as before.
3. Participate in lottery: create participation and select winner flow — ensure locking, winner selection, and payout/outbox behaviors work.
4. Run `php -l` on modified files (done during migration) and run any project test suites if available.

Rollback plan:
- If problems observed quickly, revert the commits that removed the repository files and restore original `*Repository` classes temporarily.
- Alternatively, reintroduce a thin shim `app/Repositories/SeoRepository.php` that delegates to `AdsSeoService` and bind that in the container to minimize runtime breakage while fixing the root cause.

Next steps:
- Final sweep: remove any stale imports or bindings, run integration tests and run smoke-tests on staging.
- Optional: create a smaller `LotteryQueryService` if many query helper functions remain reusable across codebase.

Contact:
- Changes made programmatically by refactor scripts on 2026-05-31. If you want, I can open a PR with the exact patches and a short test plan.
