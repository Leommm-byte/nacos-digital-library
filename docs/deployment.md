# Going live and running the site (DreamHost)

How `nacosyabatech.com` is set up, deployed, backed up and watched. Do the
one-time setup once, in order. After that, every merge to `main` that passes
CI is published automatically.

Some steps need the DreamHost panel, GitHub settings or a password: only the
owner can do those. Everything else is automated.

## Server facts

Fill these in once your account exists (step 0). The rest of this guide calls
them `USER` and `SERVER`.

| | |
|---|---|
| Plan | DreamHost Web Hosting Launch (25 GB storage; email free for the first 3 months, then paid), in NACOS's own account |
| Site | `nacosyabatech.com` |
| SSH | `USER@SERVER` (port 22): both are shown in the panel next to the user |
| Home | `/home/USER` |
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

- A NACOS email address that the executives control (not a personal one).
- A card for DreamHost.
- Admin rights on the GitHub repository (Settings).
- A Backblaze account (free; backups cost about $0.006 per GB a month).
- An UptimeRobot account (free).
- An email address for alerts, for example your own Gmail.

## 0. Your own DreamHost account and the domain

1. **Sign up.** On dreamhost.com, choose **Web Hosting Launch** (not
   DreamPress, which is for WordPress, nor a VPS, which this site doesn't
   need). Sign up with the NACOS email address. When asked for a domain,
   choose *I already own a domain* and enter `nacosyabatech.com`; skip the
   free domain offer. The first year is the cheapest; compare the 1- and
   3-year terms and the renewal price before paying.
2. **A second login.** Give a second trusted executive the panel password
   (or add them under *Account → Users/Managers*, if offered), so one person
   leaving can't lock NACOS out. Turn on two-factor sign-in for the panel.
3. **The domain.** Find out where `nacosyabatech.com` is registered:
   - **In another DreamHost account:** the hosting and the registration move
     separately. DreamHost's *Account-to-Account Site Move* only works for
     WordPress sites, and the *Transfer Domains* page is for domains coming
     from other companies, so use neither.
     - **Hosting (you can do it yourselves):** in the old account, screenshot
       the domain's DNS records and back up any `@nacosyabatech.com`
       mailboxes still in use. Then open *Manage Websites → nacosyabatech.com*
       and use **Permanently Delete Domain**. This removes the website, its
       DNS and its mailboxes, but not the registration. Then add the website
       in your account (step 5). The old site is offline in between.
     - **Registration (needs two support tickets):** each account owner
       submits a ticket from their own panel (*Support → Contact Support*,
       not the chat) with the account numbers from *Billing & Account → Manage
       Account*: "I request and approve the domain name transfer of
       nacosyabatech.com — from account number [old], to account number [new].
       I acknowledge that this transfer is of the domain name and DNS-only
       hosting." The old account must not have an unpaid balance, and the
       domain must not be about to expire.
   - **At another registrar:** leave it there and set its nameservers to
     `ns1.dreamhost.com`, `ns2.dreamhost.com` and `ns3.dreamhost.com`.
     This moves the website **and** any email at `@nacosyabatech.com` to
     your account, and can take up to 24 hours.
4. **An SSH user.** Under *Websites → SFTP Users & Files → Add User*, create
   a user with **Secure Shell Access (SSH)** on and a generated password
   (shell type `/bin/bash`). Usernames are shared by all DreamHost
   customers, so a plain one like `nacos_admin` is usually taken; the live
   site uses `nacosyct_admin`. The **Server** dropdown shows your server,
   for example `AMS1-SHARED-03`: `SERVER` is that in lower case plus
   `.dreamhost.com` (`ams1-shared-03.dreamhost.com`). New users take 5–10
   minutes to start working.
5. **The website.** Under *Websites → Add Website*, choose **Manual Setup**,
   pick that user, and under *Advanced Settings* set the **Directory** to
   `nacosyabatech.com/public` and **PHP** to 8.3. Then carry on with step 1.
6. **Check SSH works.** On your computer (Terminal on a Mac, PowerShell on
   Windows): `ssh USER@SERVER`, type the password, then `exit`.

## 1. One-time server setup

1. **PHP and HTTPS.** In the panel, go to *Websites → Manage Websites →
   nacosyabatech.com*:
   - Set **PHP** to 8.3.
   - Turn on the free **Let's Encrypt certificate** (HTTPS). It can only be
     issued once the domain points at your account (step 0.3); if it fails,
     try again a few hours later.
   - Set the **web directory** to `nacosyabatech.com/public`.

   Only `public/` may ever be served. The first deploy moves the folder the
   panel created to `nacosyabatech.com.before-deploy-<date>`; nothing is
   deleted.
2. **Database.** Under *MySQL Databases*, create a new database (for example
   `nacos_live`) and a new database user (for example `nacosyct_db`) with a
   long random password. This is a different user from the SSH one: it goes
   in `DB_USERNAME`. Note the hostname the panel shows (for example
   `mysql.nacosyabatech.com`). Don't reuse the legacy `nacos_db` password:
   it was committed to git.
3. **Mailbox.** Under *Mail → Manage Email → Create Email*, create
   `no-reply@nacosyabatech.com` as **Fully Hosted Email** (forward-only
   addresses have no login, so the site couldn't send) with a strong
   password. It sends password resets, invitations and alerts.
   On Web Hosting Launch, email is free for the first 3 months only: add
   the paid email option in the panel before then, or the site stops
   sending emails.
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
   DB_PASSWORD="…"

   SESSION_DRIVER=database
   SESSION_SECURE_COOKIE=true
   QUEUE_CONNECTION=database
   CACHE_STORE=database

   MAIL_MAILER=smtp
   MAIL_SCHEME=smtps
   MAIL_HOST=smtp.dreamhost.com
   MAIL_PORT=465
   MAIL_USERNAME=no-reply@nacosyabatech.com
   MAIL_PASSWORD="…"
   MAIL_FROM_ADDRESS=no-reply@nacosyabatech.com
   MAIL_FROM_NAME="${APP_NAME}"
   MAIL_INVITATIONS_PER_HOUR=80

   ALERT_EMAIL=you@example.com
   DISK_WARN_GB=20

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
   Keep the double quotes around the passwords, in case they contain
   characters like `#`. Save in nano with **Ctrl+O**, **Enter**, then exit
   with **Ctrl+X**. Then create the app key and keep the file private:
   ```bash
   echo "APP_KEY=base64:$(head -c 32 /dev/urandom | base64)" >> .env
   sed -i '/^APP_KEY=$/d' .env
   chmod 600 .env
   ```
   Keep a copy of `.env` in your password manager. Without `APP_KEY`, people
   can't sign in after a restore.
5. **PHP limits for uploads.** Open `~/.php/8.3/phprc` (`mkdir -p
   ~/.php/8.3 && nano ~/.php/8.3/phprc`). DreamHost may already have put a
   section at the top (it turns on opcache): leave it, and add these lines
   below it:
   ```
   upload_max_filesize = 64M
   post_max_size = 64M
   max_file_uploads = 80
   memory_limit = 256M
   max_execution_time = 300
   max_input_time = 300
   ```
   Scanned books are sent as one file per page (up to 60 pages plus a
   cover). PHP's default of 20 files per request would silently drop pages.
   The two time limits give a big upload on a slow phone connection, and
   building the book from its pages, 5 minutes instead of PHP's 30 to 60
   seconds.
6. **Cron.** The panel's *Cron Jobs* page can't run a job every minute (its
   shortest choice is every 10 minutes, and selecting all 60 minutes is
   refused), so add it over SSH instead. Log in as `USER` and run
   `crontab -e` (choose nano if it asks), then add this line at the bottom
   and save:
   ```
   * * * * * cd ~/nacosyabatech.com && /usr/local/php83/bin/php artisan schedule:run >> /dev/null 2>&1
   ```
   `crontab -l` should show it exactly once. Don't also add it in the panel,
   and if you ever change cron jobs there for this user, check `crontab -l`
   afterwards: saving in the panel can rewrite the list. (No locking is
   needed: the site already stops each task from running twice at once.)

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
   It works the same in Terminal (Mac) and PowerShell (Windows). Then put
   the public half on the server. On a Mac:
   ```bash
   ssh-copy-id -i nacos-deploy.pub USER@SERVER
   ```
   On Windows (PowerShell):
   ```powershell
   type nacos-deploy.pub | ssh USER@SERVER "mkdir -p ~/.ssh && chmod 700 ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
   ```
   Check it: `ssh -i nacos-deploy USER@SERVER` should log in without asking
   for the password.
2. **Secrets.** In the repository, go to *Settings → Secrets and variables →
   Actions → New repository secret* and add:

   | Secret | Value |
   |---|---|
   | `DEPLOY_SSH_KEY` | the whole contents of the private file `nacos-deploy` |
   | `DEPLOY_HOST` | `SERVER` |
   | `DEPLOY_USER` | `USER` |
   | `DEPLOY_KNOWN_HOSTS` | the output of `ssh-keyscan SERVER` (all lines) |

   Then delete `nacos-deploy` from your computer, or keep it only in your
   password manager.
3. **Variables.** On the same page's *Variables* tab, add:
   - `APP_URL`: `https://nacosyabatech.com`
   - `DEPLOY_ENABLED`: `true`

   Until `DEPLOY_ENABLED` is `true`, merges don't deploy.
4. **First deploy.** Go to *Actions*, click **Deploy** in the left sidebar,
   then **Run workflow** on the right (leave *rollback* unticked). Don't use
   *Re-run all jobs* on an older Deploy run: that redeploys that run's old
   commit. It takes about 3 minutes and ends by checking that
   `https://nacosyabatech.com/up` answers; if only that last check fails,
   HTTPS or the domain isn't ready yet, so run it again later.
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
- **Right after a switch.** `release.sh` stops the site's PHP processes
  (`php83.cgi`) so the next request starts on the new release; without it,
  some kept serving the old one for a few minutes (a new page, then a click
  answered by old code, e.g. "The selected status is invalid"). To check
  the name of the processes once: `ps -u USER -o comm | grep php`.

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
| `/up/scheduler` fails | Run `crontab -l` over SSH (step 1.6): the `* * * * *` line must be there once and use `/usr/local/php83/bin/php`. A change in the panel's cron page can remove it. |
| SSH says "Connection closed" right after the password | Usually a mistyped password, or a short block after several failed tries. Wait 15 minutes and paste the password instead of typing it. |
| "This username already exists" when creating a user | DreamHost usernames are shared by all customers. Pick a more specific one. |
| `/up/backups` fails | Run `php artisan backup:run` over SSH and read the error. The admin dashboard shows the last one too. |
| "mysqldump: command not found" | Add `MYSQLDUMP_PATH=/usr/bin/mysqldump` (or wherever `which mysqldump` says) to `.env`, then run `php artisan optimize`. |
| The site feels slow | See "The site feels slow" below. |
| Something broke after a deploy | Roll back (*Actions → Deploy → rollback*), then look at the alert email. |

## The site feels slow

Measure before changing anything. Every page says where its time went:

1. **In a browser.** On a computer, open the site in Chrome, press F12, open
   the *Network* tab and reload. Click the page's own row (the first one)
   and open *Timing*: under *Server Timing*, `boot` is starting PHP and
   Laravel, `db` the time spent waiting on the database (and how many
   queries), and `app` the whole request on the server. Anything else in
   the total is the network between the phone and DreamHost.
2. **On the server.** Over SSH, in `~/nacosyabatech.com`:
   ```bash
   /usr/local/php83/bin/php artisan ops:latency
   ```
   It times connecting to the database, one query, the cache (each store)
   and the disk. A page runs 10 to 30 queries, so a query over 5 ms is felt
   on every click.
3. **The slow log.** Requests over 2 seconds (`SLOW_REQUEST_MS` in `.env`)
   are written to `~/nacos/shared/storage/logs/laravel-<date>.log` as
   "Slow request", with the page, the database time and the query count:
   ```bash
   grep "Slow request" ~/nacos/shared/storage/logs/laravel-*.log | tail -20
   ```

What the numbers mean:

| Finding | What to do |
|---|---|
| `boot` is over 150 ms | OPcache is probably off. Check the top of `~/.php/8.3/phprc` still has DreamHost's opcache section (step 1.5), then run `killall -u USER php83.cgi` (or wait a few minutes) so PHP restarts. Also run `php artisan optimize`. |
| `ops:latency` shows the file cache much faster than the database cache | Set `CACHE_STORE=file` in `~/nacos/shared/.env`, then `php artisan optimize`. Sessions stay in the database (sign-out everywhere needs it). |
| One query takes over 5 ms | The database server is far from the web server, or busy. Ask DreamHost support to check that the MySQL host and the site are in the same data centre. |
| `db` is small and `app` is large | The page itself is slow. Send the slow log lines to whoever maintains the site. |
| The server numbers are small but pages still feel slow | It is the connection. Pages open almost at once when they were fetched ahead (Chrome does this when a finger lands on a link), and a bar along the top shows a page is on its way. |

Uploads: photos are shrunk on the phone, and their text is read on the
phone only when it is quick (skipped on slow or data-saving connections,
and when the site reads pages with AI). The upload keeps the screen on,
asks before the page is left, and carries on by itself after a dropped
connection, without making a copy.
