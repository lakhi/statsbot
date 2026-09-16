---
name: verify-backend
description: Run Laravel Pint and PHPUnit on the backend via Sail. Use after changes to psy-lehrprojekt-backend-main/ to confirm style and tests pass before handing off.
---

# verify-backend

Run from the repo root.

1. Confirm Sail is running:
   ```
   docker ps --filter "name=psy-lehrprojekt-backend-main" --format "{{.Names}}"
   ```
   If empty, start it: `cd psy-lehrprojekt-backend-main && ./vendor/bin/sail up -d`

2. Format check (auto-fixes):
   ```
   cd psy-lehrprojekt-backend-main && ./vendor/bin/sail php ./vendor/bin/pint --dirty
   ```
   There is no `composer run pint` script — call the binary. Keep `--dirty`: several
   older files (e.g. `AuthenticateStudent.php`) are not Pint-clean, and a bare `pint`
   reformats them wholesale, burying a small behavioural change in a whitespace diff.

3. Tests:
   ```
   cd psy-lehrprojekt-backend-main && ./vendor/bin/sail artisan test
   ```

Report back: which files Pint changed (if any) and whether the test suite was green. If any test fails, do not auto-edit — surface the failure and the file/line first.
