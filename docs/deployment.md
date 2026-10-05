# Deployment (DreamHost shared hosting)

> Draft: completed and automated in the "Go live" PR. Recorded here so the
> hosting requirements are known from day one.

## Server facts

| | |
|---|---|
| Plan | Web Hosting Growth (shared) |
| Site | `nacosyabatech.com` |
| SSH | `new_nacos_admin@iad1-shared-d12-02.dreamhost.com` (port 22) |
| Home | `/home/new_nacos_admin` |
| PHP | 8.3 |

## One-time setup

1. **Web directory:** in the panel, go to *Websites → Manage Websites →
   nacosyabatech.com → Settings* and set the web directory to
   `nacosyabatech.com/public`. Only `public/` may ever be web-served;
   everything else (`.env`, `storage/`, `legacy/`) must stay outside it.
2. **Database:** create a fresh MySQL database and user for the new app and put
   the credentials only in the server's `.env`. Do not reuse the legacy
   `nacos_db` password, which was committed to git and must be rotated.
3. **`.env`:** copy `.env.example`, then set at least:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://nacosyabatech.com
   SESSION_SECURE_COOKIE=true
   LOG_LEVEL=warning
   ```
   and run `php artisan key:generate`. For password reset and verification
   emails, set the `MAIL_*` values to a DreamHost mailbox (for example
   `no-reply@nacosyabatech.com`, SMTP `smtp.dreamhost.com`, port 465,
   `MAIL_SCHEME=smtps`).
4. **Cron:** *More → Cron Jobs*, add a job as `new_nacos_admin` with the
   *Custom* schedule set to every minute:
   ```
   cd ~/nacosyabatech.com && /usr/local/php83/bin/php artisan schedule:run >> /dev/null 2>&1
   ```

## Each release

Build the front end before uploading (`public/build` is not committed):

```bash
npm ci && npm run build
```

Then on the server:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize        # caches config, routes, views and events
```
