# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**StatsBot** — a GPT chat client built as an MVP for University of Vienna (psychology teaching project). Students authenticate via Shibboleth SSO, accept a disclaimer, and chat with a GPT model through a tutored interface. Per-student token limits are enforced; all messages are stored for scientific analysis.

## Layout

Two sibling folders under the repo root, developed together as one product:

- `psy-lehrprojekt-backend-main/` — Laravel 11 (PHP 8.2+) REST API + MySQL
- `psy-lehrprojekt-frontend-client-main/` — Angular 18 (TypeScript) SPA

Run all PHP/Composer/Artisan commands from inside the backend folder; all `ng` / `npm` commands from inside the frontend folder.

## Backend essentials

- **Auth**: `app/Http/Middleware/AuthenticateStudent` runs on every API request. It reads the `uid` (u:account-ID) from Shibboleth server variables, finds or rejects the student in DB, and merges the student object onto the request. Do not bypass this middleware on protected routes.
- **Routes**: `routes/api.php` defines the full API surface — get authenticated student, register, list/get chat sessions, send message to GPT (with question/answer persistence).
- **GPT proxy**: the backend forwards user prompts to the configured GPT endpoint, persists prompt/completion/total token counts to `history`, and decrements the student's remaining tokens. **New GPT models are configured in `.env` — do not hardcode model names or endpoints.**
- **Models**: `App\Models\Student`, `App\Models\History` map automatically to the `student` and `history` tables.

### Database schema notes (non-obvious)

- `student.activated` — boolean flag to disable a student's access without deleting the row.
- `student.token_limit` / remaining-tokens column — set on registration, can be edited per-row.
- `history.started` — this is a **timestamp that doubles as a chat-session ID**. All messages sharing the same `started` value belong to one dialog. Do not refactor this into a separate `chat_sessions` table without a migration plan; existing rows depend on this grouping.
- `history` stores prompt_tokens, completion_tokens, and total_tokens per message.

### Backend commands

- Dev stack: `./vendor/bin/sail up` (brings up app on :80, MySQL, phpMyAdmin on :8081, Vite on :5173)
- Tests: `./vendor/bin/sail artisan test` (PHPUnit)
- Format: `./vendor/bin/sail composer run pint` (Laravel Pint — the only configured formatter)

Do not run `php artisan` directly when Sail is in use; the DB connection won't resolve. Use `./vendor/bin/sail artisan ...` instead.

## Frontend essentials

- **API base URL** lives in `src/environments/environment.ts` (dev) and `environment.prod.ts` (prod). Do not hardcode backend URLs in components or services.
- **`DataService`** is the single channel between Angular and the backend — new API calls should go through it, not direct `HttpClient` calls in components.
- **`AppComponent`** loads the current student on startup and chooses between `RegisterComponent` (disclaimer) and `ChatComponent` based on registration state.
- **`ChatComponent`** owns chat + history. A static tutor welcome message is shown locally but **stripped before sending to the backend** (see `DataService`). The history view groups by `started` (see backend schema note above).

### Frontend commands

- Dev server: `npm start` (serves on :4200)
- Tests: `npm test` (Karma/Jasmine, opens a browser — use `npm test -- --watch=false --browsers=ChromeHeadless` for CI/headless runs)
- Lint: `npm run lint` (Angular ESLint with `@angular-eslint` + `@typescript-eslint` rules; auto-fix many with `npm run lint -- --fix`)

The initial lint run on this codebase surfaces real issues (unused vars, missing `const`, constructor-injection over `inject()`). Treat new lint errors in your changes as blockers; pre-existing errors are a known cleanup backlog — don't reformat unrelated files in passing.

## Deployment context

- Production runs on University of Vienna ZID webspace.
- Backend files live **outside** `public_html`; frontend build output goes **inside** `public_html`.
- A `.htaccess` restricts access to UniVie students/employees; required Shibboleth attributes must be enabled in the webspace admin.
- Frontend deploy = `npm run build` then transfer `dist/` contents to the webspace.
- **The webspace is a containerized OpenShift deployment** (PHP 8.2 image, files on a PVC). The OpenShift API (`:6443`) and the SFTP gateway home are **not reachable** for direct push, so deploys are **pull-based**: publish the build to a GitHub release, then the pod pulls + applies it. Use the **`deploy-frontend` skill** (`.claude/skills/deploy-frontend/SKILL.md`) — it runs `scripts/publish-frontend.sh` and hands you the one-line paste for the pod Terminal. In-pod apply/rollback logic lives in `scripts/webspace-deploy.sh` + `scripts/webspace-rollback.sh` (copied once to `/var/www/`). Docroot is `/var/www/html`; namespace `lehrprojeg67`.

## Conventions

- When changing API request/response shapes, update both `routes/api.php` + the matching `DataService` method in the frontend in the same change.
- When adding new env-driven config, add a corresponding entry to `.env.example` so other developers know it exists.
- Comments in existing code (German + English mix) document intent — preserve language style when editing nearby code.
