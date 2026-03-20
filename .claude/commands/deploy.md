# Production Deployment Sequence

When invoked, you must execute the following deployment steps exactly in this order. Use your Bash tools to run these commands carefully.

1. SSH into the server: `ssh root@209.97.153.234`
2. Navigate to project: `cd /var/www/pw2d`
3. Pull changes: `git pull origin main` (If there are conflicts, NEVER force push. Reset to origin/main).
4. Run migrations if needed: `php artisan migrate --force`
5. Build frontend assets: `npm run build`
6. Clear caches: `php artisan optimize:clear`
7. Restart PHP-FPM to clear OPcache: `systemctl restart php8.3-fpm`

Confirm with the user once the deployment sequence is fully completed.
