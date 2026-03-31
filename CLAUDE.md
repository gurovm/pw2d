# Pw2D (Power to Decide) - Project Rules

AI-driven multi-vendor price aggregator & comparison platform. Full system docs: `docs/project_context.md`. DB schema: `docs/database-schema.md`.

## Tech Stack
- **Backend:** Laravel 11 (PHP 8.3) | **Frontend:** Blade + Tailwind + Livewire v3 | **Database:** MySQL | **AI:** Gemini API via AiService

## Critical Boundaries

**Deployment:** NEVER initiate deployment automatically. No SSH, no build scripts. Deployment is strictly via `/deploy` command.

**AI Calls:** All AI calls MUST go through `AiService` (domain methods) or `GeminiService` (admin-only raw prompts). Never call GeminiService/Gemini HTTP API directly from controllers, jobs, or Livewire components.

**Chrome Extension:** NEVER alter API endpoint URLs without simultaneously updating `popup.js` and `content.js`. Full extension docs in `docs/project_context.md` Section 6.

**TAU Chatbox:** Separate Python/FastAPI/Docker project on `t.pw2d.com` (Port 8010). Do not confuse with this Laravel codebase.

## Coding Standards
- Write clean, modern PHP 8.3 (strict types, match expressions, arrow functions).
- Tailwind utility classes for styling; avoid custom CSS unless absolutely necessary.
- Always review `routes/web.php` and Controller logic before proposing architecture changes.

## Testing Requirements
- Every new feature, API endpoint, or core logic update MUST have tests.
- Use Pest (preferred) or PHPUnit. Cover happy path + edge cases + error handling.
- Run `php artisan test` locally before completing a task.

## Database
Full schema: `docs/database-schema.md`. Key entities: Product→ProductOffer, Category→Feature→ProductFeatureValue, AiMatchingDecision.

## Agent Team
Agent definitions live in `.claude/agents/`. Use sub-agents sparingly — each costs ~15K-30K tokens of context loading.

## Workflow & Behavioral Rules
* **Plan First:** For non-trivial tasks (3+ steps), write a plan to `docs/todo.md`. Wait for approval before coding.
* **Self-Improvement Loop:** After ANY correction, update `docs/lessons.md` to prevent the same mistake.
* **Verification Before Done:** Run tests, check logs, demonstrate correctness. "Would a Staff Engineer approve this?"
* **Autonomous Bug Fixing:** Given a bug report or error — just fix it. Find root cause, resolve it.
* **Demand Elegance:** Simplest solution possible. No over-engineering. Senior developer standards.
