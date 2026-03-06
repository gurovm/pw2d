# Pw2D (Power to Decide) - AI Project Rules & Context

## 1. Project Overview
Pw2D is a modern affiliate/recommendation platform targeted at the US market. Its core feature is "Compare with Intelligence" - an AI-driven search that takes natural language inputs from users (e.g., use case, budget, preferences), matches them to the right product category, and dynamically ranks items based on their specific needs.

## 2. Tech Stack & Architecture
- **Backend:** Laravel 11 (PHP 8.3)
- **Frontend:** Blade Templates, Tailwind CSS, compiled via Vite (Node.js v20)
- **Database:** MySQL
- **AI Integration:** Uses AI models to parse user prompts and rank database items.

## 3. Environments & Server Infrastructure
- **Local Environment:** Standard Laravel Valet/Serve development. Database is local.
- **Production Server:** - **IP:** 209.97.153.234 (DigitalOcean, Ubuntu 24.04 LTS)
  - **Web Server:** Nginx (`pw2d.com`, `www.pw2d.com`)
  - **Path:** `/var/www/pw2d`
  - **SSL:** Secured via Certbot.
- **Sub-system (TAU Chatbox):** There is a separate Python/FastAPI/Docker project running on the same server under `t.pw2d.com` (Port 8010). Do not confuse the Laravel monolithic codebase with the TAU Chatbox project.

## 4. STRICT Deployment Workflow (CRITICAL)
- **NEVER** edit files directly on the production server via SSH.
- All code changes must be made locally, committed, and pushed to GitHub (`origin/main`).
- **To Deploy to Production:**
  1. SSH into the server: `ssh root@209.97.153.234`
  2. Navigate to project: `cd /var/www/pw2d`
  3. Pull changes: `git pull origin main` (If there are conflicts, NEVER force push from the server. Reset to origin/main).
  4. Run migrations if needed: `php artisan migrate --force`
  5. Build frontend assets: `npm run build`
  6. Clear caches: `php artisan optimize:clear`

## 5. Coding Standards
- Write clean, modern PHP 8.3 (use strict types, match expressions, arrow functions).
- Frontend changes should utilize Tailwind utility classes; avoid writing custom CSS unless absolutely necessary.
- Measurements in recipes/specs should prioritize logical, standard metric/imperial units clearly. 
- Always review `routes/web.php` and Controller logic before proposing architecture changes.
## 6. Testing Requirements (TDD/Automated Tests)
- Every new feature, API endpoint, or core logic update MUST be accompanied by a corresponding test.
- Use Laravel's built-in testing tools (Pest or PHPUnit).
- Tests must cover the "happy path" as well as edge cases and error handling.
- Run `php artisan test` locally to verify functionality before completing a task.
