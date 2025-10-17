# THEME-008 — Running Checklist

## Quality Gates
- [x] Snapshots captured (Slate/Flatly desktop + mobile)
- [x] Axe scans clean (Slate/Flatly desktop)
- [x] Motion tests cover prefers-reduced-motion
- [x] Manual QA checklist logged

### Manual QA Summary
- Verified login + dashboard render across Slate/Flatly via Playwright suite; no regressions observed in primary nav or notifications.
- Exercised theme selections through snapshot, motion, and hot-swap specs (mocked settings API updates applied inline); persistence confirmed without full reload.
- Smoke-checked admin theming/branding flows under mocked API responses; real backend ETag/asset storage still required for full integration sign-off.

## Session Notes
- Session A: Restored Playwright mocks for theme/prefs/settings, regenerated Slate/Flatly/Darkly baseline snapshots, and confirmed desktop suite green.
- Session B: Authored `tests/e2e/app-snapshots.spec.ts`, generated eight-screen baselines for Slate/Flatly (desktop + 375×812 mobile), and captured paths for inclusion in QA artifacts.
- Session C: Added `tests/e2e/accessibility.spec.ts` with axe-core coverage for Slate/Flatly desktop surfaces, stabilized mocks, addressed contrast/heading issues across dashboard+admin views, and produced passing accessibility runs.
- Session D: Landed `tests/e2e/motion.spec.ts` validating motion design tokens and `/auth/login` reduced-motion behavior for Slate/Flatly, with targeted Playwright runs recorded.
- Session E (2025-10-17): Refreshed theme + app snapshot baselines after stabilizing status banners, instrumented stateful mocks for `/api/settings/ui` + `/api/me/prefs/ui`, re-enabled `theme-hot-swap` suite (inline mock endpoint), and re-ran `npm run lint`, targeted Playwright suites (`theme`, `app-snapshots`, `accessibility`, `motion`, `theme-hot-swap`), plus full `npm run test:e2e` with green results ahead of final handoff.
