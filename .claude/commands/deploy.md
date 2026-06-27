# Production Deployment Sequence

When invoked, you must execute the following deployment steps exactly in this order. Use your Bash tools to run these commands carefully.

1. SSH into the server: `ssh root@209.97.153.234`
2. Navigate to project: `cd /var/www/pw2d`
3. Pull changes: `git pull origin main` (If there are conflicts, NEVER force push. Reset to origin/main).
4. Install dependencies: `composer install --no-dev --optimize-autoloader`
5. Republish Livewire frontend assets (idempotent): `php artisan vendor:publish --tag=livewire:assets --force`
   Keeps published `livewire.js` in sync with the installed Livewire version. If skipped after a
   Livewire composer bump, prod serves a stale `livewire.js` whose older bundled Alpine breaks
   x-init expressions (console: "Livewire assets out of date" + "Alpine Expression Error"). See docs/lessons.md.
6. Run migrations if needed: `php artisan migrate --force`
7. Build frontend assets: `npm run build`
8. Clear caches: `php artisan optimize:clear`
9. Restart PHP-FPM to clear OPcache: `systemctl restart php8.3-fpm`
10. Verify Laravel scheduler cron hook is installed (idempotent read-only check):
   `sudo -u www-data crontab -l 2>/dev/null | grep -q "schedule:run" || echo "WARNING: Laravel scheduler cron hook NOT installed. Run: echo '* * * * * cd /var/www/pw2d && php artisan schedule:run >> /dev/null 2>&1' | sudo -u www-data crontab -"`

   If the check fails (WARNING is printed), surface the warning to the user EXPLICITLY at the end of the deployment summary. Do NOT auto-install; this is a one-time per-server setup that needs human confirmation.

Confirm with the user once the deployment sequence is fully completed. If step 9 printed a WARNING, include it prominently in the confirmation message.
