# Going live and running the site (DreamHost)

How `nacosyabatech.com` is set up, deployed, backed up and watched. Do the
one-time setup once, in order. After that, every merge to `main` that passes
CI is published automatically.

Some steps need the DreamHost panel, GitHub settings or a password: only the
owner can do those. Everything else is automated.

## Server facts

| | |
|---|---|
| Plan | Web Hosting Growth (shared) |
| Site | `nacosyabatech.com` |
| SSH | `new_nacos_admin@iad1-shared-d12-02.dreamhost.com` (port 22) |
| Home | `/home/new_nacos_admin` |
| PHP | 8.3 (`/usr/local/php83/bin/php`) |

## How it fits together

```
~/nacosyabatech.com        → link to the live release (the panel's web
                             directory stays nacosyabatech.com/public)
~/nacos/releases/<name>/   the last 3 releases, one folder each
~/nacos/shared/.env        the live settings (never in git)
~/nacos/shared/storage/    uploads, logs, sessions, backups
~/nacos/shared/live/       live election results
~/nacos/release.sh         the deploy script (scripts/deploy/release.sh)
```

A deploy builds everything on GitHub, uploads one file, puts the site in
maintenance mode for the few seconds the database update takes, then switches
the link. Rolling back switches the link back.

## You will need

- The DreamHost panel and SSH password for `new_nacos_admin`.
- Admin rights on the GitHub repository (Settings).
- A Backblaze account (free; backups cost about $0.006 per GB a month).
- An UptimeRobot account (free).
- An email address for alerts, for example your own Gmail.

## 1. One-time server setup

1. **PHP and HTTPS.** In the panel, go to *Websites → Manage Websites →
   nacosyabatech.com*:
   - Set **PHP** to 8.3.
   - Turn on the free **Let's Encrypt certificate** (HTTPS).
   - Set the **web directory** to `nacosyabatech.com/public`.

   Only `public/` may ever be served. The first deploy moves the folder the
   panel created to `nacosyabatech.com.before-deploy-<date>`; nothing is
   deleted.
2. **Database.** Under *MySQL Databases*, create a new database (for example
   `nacos_live`) and a new user with a long random password. Note the
   hostname the panel shows (for example `mysql.nacosyabatech.com`). Don't
   reuse the legacy `nacos_db` password: it was committed to git.
3. **Mailbox.** Under *Mail → Manage Email*, create `no-reply@nacosyabatech.com`
   with a strong password. It sends password resets, invitations and alerts.
4. **Settings file.** Log in over SSH and create `~/nacos/shared/.env`:
   ```bash
   mkdir -p ~/nacos/shared && cd ~/nacos/shared
   nano .env
   ```
   Paste this, filling in the values:
   ```
   APP_NAME="NACOS YabaTech"
   APP_ENV=production
   APP_KEY=
   APP_DEBUG=false
   APP_URL=https://nacosyabatech.com
   APP_DISPLAY_TIMEZONE=Africa/Lagos
   LOG_CHANNEL=daily
   LOG_LEVEL=warning

   DB_CONNECTION=mysql
   DB_HOST=mysql.nacosyabatech.com
   DB_PORT=3306
   DB_DATABASE=nacos_live
   DB_USERNAME=…
   DB_PASSWORD=…

   SESSION_DRIVER=database
   SESSION_SECURE_COOKIE=true
   QUEUE_CONNECTION=database
   CACHE_STORE=database

   MAIL_MAILER=smtp
   MAIL_SCHEME=smtps
   MAIL_HOST=smtp.dreamhost.com
   MAIL_PORT=465
   MAIL_USERNAME=no-reply@nacosyabatech.com
   MAIL_PASSWORD=…
   MAIL_FROM_ADDRESS=no-reply@nacosyabatech.com
   MAIL_FROM_NAME="${APP_NAME}"
   MAIL_INVITATIONS_PER_HOUR=80

   ALERT_EMAIL=you@example.com
   DISK_WARN_GB=40

   # Filled in at step 3 (backups).
   BACKUP_DISK=
   BACKUP_S3_KEY=
   BACKUP_S3_SECRET=
   BACKUP_S3_REGION=
   BACKUP_S3_BUCKET=
   BACKUP_S3_ENDPOINT=

   # Optional: AI text for scanned uploads and AI answers in the assistant.
   ANTHROPIC_API_KEY=
   ```
   Then create the app key and keep the file private:
   ```bash
   echo "APP_KEY=base64:$(head -c 32 /dev/urandom | base64)" >> .env
   sed -i '/^APP_KEY=$/d' .env
   chmod 600 .env
   ```
   Keep a copy of `.env` in your password manager. Without `APP_KEY`, people
   can't sign in after a restore.
5. **PHP limits for uploads.** Create `~/.php/8.3/phprc`:
   ```
   upload_max_filesize = 64M
   post_max_size = 64M
   max_file_uploads = 80
   memory_limit = 256M
   ```
   Scanned books are sent as one file per page (up to 60 pages plus a
   cover). PHP's default of 20 files per request would silently drop pages.
6. **Cron.** Under *More → Cron Jobs*, add a job for `new_nacos_admin` with
   a *Custom* schedule of every minute and this command:
   ```
   cd ~/nacosyabatech.com && /usr/local/php83/bin/php artisan schedule:run >> /dev/null 2>&1
   ```
   It drives everything that happens in the background:
   - the email queue and invitations;
   - closing elections on time;
   - backups and the disk check;
   - the heartbeat that UptimeRobot watches.

## 2. GitHub: automatic deploys

1. **A deploy key.** On your computer (not the server), run:
   ```bash
   ssh-keygen -t ed25519 -f nacos-deploy -N "" -C "github-deploy"
   ```
   Then copy the public half onto the server:
   ```bash
   ssh-copy-id -i nacos-deploy.pub new_nacos_admin@iad1-shared-d12-02.dreamhost.com
   ```
2. **Secrets.** In the repository, go to *Settings → Secrets and variables →
   Actions → New repository secret* and add:

   | Secret | Value |
   |---|---|
   | `DEPLOY_SSH_KEY` | the whole contents of the private file `nacos-deploy` |
   | `DEPLOY_HOST` | `iad1-shared-d12-02.dreamhost.com` |
   | `DEPLOY_USER` | `new_nacos_admin` |
   | `DEPLOY_KNOWN_HOSTS` | the output of `ssh-keyscan iad1-shared-d12-02.dreamhost.com` |

   Then delete `nacos-deploy` from your computer, or keep it only in your
   password manager.
3. **Variables.** On the same page's *Variables* tab, add:
   - `APP_URL`: `https://nacosyabatech.com`
   - `DEPLOY_ENABLED`: `true`

   Until `DEPLOY_ENABLED` is `true`, merges don't deploy.
4. **First deploy.** Go to *Actions → Deploy → Run workflow*. It takes about
   3 minutes and ends by checking that `https://nacosyabatech.com/up`
   answers.
5. **Departments and the first admin.** Over SSH:
   ```bash
   cd ~/nacosyabatech.com
   /usr/local/php83/bin/php artisan db:seed --force    # departments only (no demo data in production)
   ```
   Then sign up on the site with your own matric number, and run:
   ```bash
   /usr/local/php83/bin/php artisan users:make-admin F/HD/24/1234567
   ```
   Make further admins from *Admin → Users*.

### Each release, and rolling back

- **Releases.** Merge to `main`. When CI passes, the Deploy workflow
  publishes it. The version (commit) is shown on the admin dashboard under
  *Site health*.
- **Rolling back.** Go to *Actions → Deploy → Run workflow*, tick
  **rollback**, then *Run*. The previous release is live within seconds.
  Database changes made by the newer release stay in place: our database
  updates only add things, so the older code still works.
- **A failed database update.** The new release isn't switched to, and the
  site stays on the old one. Both the workflow and the alert email say what
  failed.

## 3. Backups (Backblaze B2)

Every night at 02:30 the database is dumped and copied off-site. Every hour,
new uploads (books, covers and photos) are copied off-site, a few hundred MB
at a time.

- **Off-site:** the last dump of each of the last 7 days, 4 weeks and 6
  months is kept.
- **On the server:** the last 3 dumps are kept, in `~/nacos/shared/storage/app/backups`.
- **Deleted books:** a book deleted on the site is kept off-site, in case
  deleting it was a mistake.

**Set it up:**

1. In Backblaze, go to *B2 Cloud Storage → Buckets → Create a Bucket*:
   - Name it, for example `nacos-yabatech-backups`.
   - Set it to **Private**, with default encryption **on**.
   - In the bucket's *Lifecycle Settings*, choose **Keep only the last
     version**.
   - Note the **Endpoint** shown on the bucket, for example
     `s3.us-west-004.backblazeb2.com`.
2. Under *Application Keys → Add a New Application Key*, give the key
   access to **only this bucket**, with Read and Write. Note the *keyID* and
   *applicationKey*; the key is shown only once.
3. Fill in the backup lines of `~/nacos/shared/.env`:
   ```
   BACKUP_DISK=backups
   BACKUP_S3_KEY=<keyID>
   BACKUP_S3_SECRET=<applicationKey>
   BACKUP_S3_REGION=us-west-004
   BACKUP_S3_BUCKET=nacos-yabatech-backups
   BACKUP_S3_ENDPOINT=https://s3.us-west-004.backblazeb2.com
   ```
   Then refresh the settings cache and run the first backup:
   ```bash
   cd ~/nacosyabatech.com
   /usr/local/php83/bin/php artisan optimize
   /usr/local/php83/bin/php artisan backup:run
   ```
   It should say "Database saved as db-… and copied off-site". The bucket
   should then show a `nacos/db/` folder, and `nacos/files/` once there are
   uploads.

**Restore (practise once before launch):**

1. Download a dump: in the bucket's *Browse Files*, open `nacos/db/` and
   download the newest file. Or copy it from
   `~/nacos/shared/storage/app/backups`.
2. Load it into a database. Use a new, empty one to practise; use the live
   database only in a real emergency:
   ```bash
   gunzip -c db-20261012-013000.sql.gz | mysql -h mysql.nacosyabatech.com -u USER -p DATABASE
   ```
3. Restore the uploaded files by downloading `nacos/files/` from the bucket
   into `~/nacos/shared/storage/app/private/`. The B2 web page, or the free
   tool `rclone`, can download a whole folder.
4. Keep the same `APP_KEY` in `.env`. Restored accounts need it to sign in.

## 4. Error alerts

With `ALERT_EMAIL` set, any error on the live site is emailed. The email
includes:
- what failed;
- where;
- which page (with links and tokens hidden);
- the signed-in user's id;
- the version.

Failed backups and low disk space are emailed too. The same error is sent at
most once an hour (with a count), and no more than 10 alerts an hour.
Everything is also logged in `~/nacos/shared/storage/logs/`.

To test it, over SSH run:
```bash
cd ~/nacosyabatech.com && /usr/local/php83/bin/php artisan tinker --execute "report(new RuntimeException('Test alert'))"
```

## 5. Uptime checks (UptimeRobot)

In UptimeRobot, add three **HTTP(s)** monitors. Use a 5-minute interval and
alert by email (and the phone app, if you like):

| Name | URL | It fails when |
|---|---|---|
| NACOS site | `https://nacosyabatech.com/up` | the site or PHP is down |
| NACOS cron | `https://nacosyabatech.com/up/scheduler` | the cron hasn't run for 5 minutes, so emails, invitations, election closing and backups have stopped |
| NACOS backups | `https://nacosyabatech.com/up/backups` | there's been no database backup copied off-site in 26 hours |

Each URL returns `OK` when all is well, or says what's wrong. The admin
dashboard's *Site health* section shows the same checks.

## 6. Launch checklist

Before telling students, check each item:

- [ ] `https://nacosyabatech.com` loads with the padlock, and
      `http://nacosyabatech.com` redirects to it.
- [ ] *Admin → Dashboard → Site health*:
  - [ ] Cron says **Running**.
  - [ ] Database backup says **Up to date**.
  - [ ] Version shows the latest commit.
- [ ] The three UptimeRobot monitors are green.
- [ ] The test alert email arrived (section 4).
- [ ] Password reset: add your email in *Settings*, verify it, then use
      "Forgot password". Both emails arrive and aren't in spam.
- [ ] Sign up as a test student, read a book, save it, sign out, then remove
      the test account in *Admin → Users*.
- [ ] Upload a small PDF as the test student, then approve it as admin.
- [ ] Upload one class's nominal roll, then use *Create accounts → email
      invitations* on one student whose email you control. The invitation
      arrives within a few minutes and its link works.
- [ ] Create a test election that ends in 10 minutes and vote. Live results
      update, and it closes on time. Then delete it.
- [ ] On a phone: install the app from the home page and open it.
- [ ] Practise a restore (section 3).
- [ ] Rotate the legacy `nacos_db` password and remove the old legacy site
      from the server, if it's still there.

## Troubleshooting

| What you see | What to do |
|---|---|
| The deploy fails at "SSH key" or "Upload" | Check the four `DEPLOY_*` secrets. `DEPLOY_KNOWN_HOSTS` must be the `ssh-keyscan` output. |
| The deploy says ".env is missing" | Create `~/nacos/shared/.env` (step 1.4). |
| The site shows "500" after a settings change | Run `php artisan optimize` in `~/nacosyabatech.com`, since settings are cached. |
| `/up/scheduler` fails | Check the cron job in the panel (step 1.6). It must use `/usr/local/php83/bin/php`. |
| `/up/backups` fails | Run `php artisan backup:run` over SSH and read the error. The admin dashboard shows the last one too. |
| "mysqldump: command not found" | Add `MYSQLDUMP_PATH=/usr/bin/mysqldump` (or wherever `which mysqldump` says) to `.env`, then run `php artisan optimize`. |
| Something broke after a deploy | Roll back (*Actions → Deploy → rollback*), then look at the alert email. |
