# Production Deployment Sequence

When invoked, you must execute the following deployment steps exactly in this order. Use your Bash tools to run these commands carefully.

1. SSH into the server: `ssh root@209.97.153.234`
2. Navigate to project: `cd /var/www/pw2d`
3. Pull changes: `git pull origin main` (If there are conflicts, NEVER force push. Reset to origin/main).
4. Install dependencies: `composer install --no-dev --optimize-autoloader`
5. Run migrations if needed: `php artisan migrate --force`
6. Build frontend assets: `npm run build`
7. Clear caches: `php artisan optimize:clear`
8. Restart PHP-FPM to clear OPcache: `systemctl restart php8.3-fpm`

Confirm with the user once the deployment sequence is fully completed.
