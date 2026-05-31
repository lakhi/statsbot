---
name: verify-frontend
description: Run Angular build + headless Karma tests on the frontend. Use after changes to psy-lehrprojekt-frontend-client-main/ to confirm the app builds and unit tests pass.
---

# verify-frontend

Run from the repo root.

1. Build (catches type errors and template issues):
   ```
   cd psy-lehrprojekt-frontend-client-main && npm run build
   ```

2. Tests, headless and non-watching:
   ```
   cd psy-lehrprojekt-frontend-client-main && npm test -- --watch=false --browsers=ChromeHeadless
   ```

If `ChromeHeadless` is unavailable on this machine, fall back to `--browsers=ChromeHeadlessNoSandbox` (requires a karma.conf customLauncher — surface that as a gap rather than silently editing karma.conf).

Report back: build success/failure with the first error, and the Karma summary line (X specs, Y failures). Do not auto-edit on test failure — surface the failing spec first.
