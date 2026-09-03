# Deploying DropRSVP on cPanel (no Node, no SSR)

The app ships **prebuilt** — `public/build` (compiled JS/CSS) is committed, so the
server never needs Node/npm. SEO is rendered by PHP into the HTML `<head>`, so it
works with JavaScript disabled. You only need **PHP 8.2+, Composer, and MySQL**.

There are two supported layouts. **Option A is strongly recommended** — it's the
cleanest, most secure, and needs no bridge files or symlinks.

---

## Option A — point the document root at `public/` (recommended)

You do **not** need to move `index.php`. Instead tell cPanel to serve the app's
own `public/` folder as the web root. Everything (assets, `/storage`, favicons,
sitemap, SEO) then resolves natively.

1. **Clone** anywhere in your account, e.g. `public_html/droprsvp` or `~/droprsvp`:
   ```bash
   git clone https://github.com/website-f/droprsvp.git droprsvp
   cd droprsvp
   composer install --no-dev --optimize-autoloader
   cp .env.example .env    # then edit — see "Environment" below
   php artisan key:generate
   php artisan migrate --force --seed
   php artisan storage:link
   php artisan config:cache && php artisan route:cache
   ```
2. **Set the document root** to the app's `public/` folder:
   - **Subdomain** (e.g. `events.yourdomain.com`): cPanel → *Domains* → *Create A
     New Domain* → set **Document Root** = `public_html/droprsvp/public`.
   - **Existing domain**: cPanel → *Domains* → your domain → **edit the Document
     Root** to `public_html/droprsvp/public`. (On some hosts this is under
     *Addon/Subdomains* or you ask support to change it — it's a standard request.)
3. Done. `git pull` to update; re-run `composer install`, `migrate --force`,
   `config:cache` if code/DB changed. **No build step, ever.**

> Why this is best: only `public/` is web-exposed, so `.env`, source, and the
> database are never reachable over HTTP. Nothing to copy or symlink.

---

## Option B — `index.php` at the `public_html` root (host locks the doc root)

Use this only if your host will **not** change the document root off `public_html`.

**Recommended layout — clone ABOVE the web root** (keeps `.env`/source private):

```
/home/USER/
├── droprsvp/                 ← git clone (app code, .env, vendor — NOT web-served)
│   ├── public/build/         ← committed compiled assets
│   ├── public/og-default.png, logo.png, favicon.*
│   └── storage/app/public/   ← uploaded images (after storage:link)
└── public_html/              ← the web root
    ├── index.php             ← copy of deploy/public_html-index.php
    ├── .htaccess             ← copy of droprsvp/public/.htaccess
    ├── build        ─┐ symlinks into the app's public/ folder
    ├── storage      ─┤
    ├── og-default.png│
    ├── logo.png      │
    ├── favicon.ico / favicon.svg / apple-touch-icon.png ┘
```

Steps:

1. Clone + install (same commands as Option A, step 1) into `~/droprsvp`
   (one level above `public_html`).
2. Put the front controller + rewrite rules at the web root:
   ```bash
   cd ~/public_html
   cp ../droprsvp/deploy/public_html-index.php index.php
   cp ../droprsvp/public/.htaccess .htaccess
   ```
   `index.php` already points at `__DIR__.'/../droprsvp'` and sets the public path
   to `public_html` — no edit needed for this layout.
3. Expose the public assets by symlinking **every** entry of the app's `public/`
   into the web root (skip the bridge `index.php`). This one loop covers
   `build/`, `vector/`, `storage`, `og-default.png`, `logo.png`, favicons and
   anything added later — re-run it after each deploy. The symlinks point outside
   the web root's own tree, so the app source stays private:
   ```bash
   cd ~/public_html
   APP_PUBLIC="$HOME/droprsvp/public"     # adjust to your app's public/ folder
   for f in "$APP_PUBLIC"/*; do
     n=$(basename "$f")
     [ "$n" = "index.php" ] && continue
     ln -sfn "$f" "$n"
   done
   ls -la
   ```
   (`php artisan storage:link` first, so `public/storage` exists to be linked.)
   If your host disallows symlinks, `cp -r "$APP_PUBLIC"/* .` instead — but then
   re-copy after every deploy.
4. If the symlinked assets return 403/404, your host isn't following symlinks.
   Add this as the **first line** of `public_html/.htaccess`:
   ```apache
   Options +FollowSymLinks
   ```
   If that throws a 500 (`Options not allowed here`), your host forbids it —
   delete the symlinks and use `cp -r` copies instead (see the update routine).

> If you must keep the clone **inside** `public_html` (`public_html/droprsvp`),
> edit `index.php` to `$appRoot = __DIR__.'/droprsvp'` **and** add
> `public_html/droprsvp/.htaccess` containing `Require all denied` so nobody can
> fetch `/droprsvp/.env`. Then expose assets by copying `public/build` to
> `public_html/build` on each deploy (symlinks into a denied folder won't serve).
> Cloning above the web root (the layout shown) avoids all of this — prefer it.

---

## Environment (`.env`) — the keys that matter for assets + SEO

```dotenv
APP_NAME="DropRSVP"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com        # ← MUST be the real, public URL (https).
                                      #   Drives canonical, og:url, JSON-LD, sitemap.

# Database (MySQL)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=your_db
DB_USERNAME=your_user
DB_PASSWORD=your_pass

# Client-side rendering (no Node → SSR stays off; this is the default)
INERTIA_SSR_ENABLED=false

# Payments (CHIP Collect) — go live by switching the driver + keys.
# Test vs live is decided by the key itself (no separate mode).
CHIP_DRIVER=chip
CHIP_SECRET_KEY=...      # Secret Key from the CHIP portal
CHIP_BRAND_ID=...        # Brand UUID from the CHIP portal
# CHIP_PUBLIC_KEY=...    # optional; else fetched from /public_key/ and cached

# Email (cPanel SMTP) — all transactional emails go out through this.
MAIL_MAILER=smtp
MAIL_SCHEME=smtps                       # port 465 = implicit TLS. (587 → use "tls")
MAIL_HOST=mail.yourdomain.com
MAIL_PORT=465
MAIL_USERNAME=no-reply@yourdomain.com
MAIL_PASSWORD=...                        # the mailbox password
MAIL_FROM_ADDRESS="no-reply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"
MAIL_EHLO_DOMAIN=yourdomain.com          # keep the HELO on the real domain

# SEO (optional overrides — sensible defaults already ship)
# SEO_DEFAULT_IMAGE=/og-default.png   # branded 1200×630 default (already set)
# SEO_LOGO=/logo.png                  # Organization logo (already set)
# SEO_TWITTER=@yourhandle
# SEO_LOCALE=en_US
```

- **`APP_URL` is the one that prevents SEO errors.** Every canonical tag, `og:url`,
  JSON-LD `url`, and the sitemap are built from it. Set it to your live `https://`
  domain and they're all correct.
- Assets never 404 as long as `/build` resolves at the web root — Option A gives
  that for free; Option B via the `build` symlink/copy. No `ASSET_URL` needed.
- **Email:** after setting the `MAIL_*` block, run `php artisan config:cache`, then
  test everything with `php artisan mail:test you@example.com` (sends one of every
  transactional email with sample data — nothing is written to the DB). Because
  `APP_URL` is the live domain in production, the links inside emails are correct;
  in local dev pass `--url=https://www.droprsvp.com` so `.test` links (which some
  spam filters block) don't leak in. For deliverability, enable **SPF + DKIM** (and
  ideally **DMARC**) for the domain in cPanel → *Email Deliverability*.

---

## Update routine

**Use the script — it does everything, including re-exposing assets:**

```bash
bash ~/droprsvp/deploy/deploy.sh      # override with APP_DIR=… WEB_ROOT=… if needed
```

It runs `git pull` → `composer install` → `migrate --force` → `storage:link` →
`config:cache`/`route:cache`, then **re-runs the symlink loop** so any file newly
added to `public/` (a logo, image, or build chunk) is exposed. **This last step
is the one people forget** — without it, new public files 404 even though
`git pull` fetched them (they exist in the app's `public/`, but no symlink points
to them from the web root yet).

Manual equivalent (Option B / symlink layout):

```bash
cd ~/droprsvp   # or public_html/droprsvp
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache
# RE-EXPOSE public/ into the web root (adds symlinks for any new files):
cd ~/public_html
APP_PUBLIC="$HOME/droprsvp/public"
for f in "$APP_PUBLIC"/*; do n=$(basename "$f"); [ "$n" = "index.php" ] && continue; ln -sfn "$f" "$n"; done
# If your host uses copies instead of symlinks: cp -r "$APP_PUBLIC"/* ~/public_html/
```

## Scheduler cron (required for one job)

DropRSVP has a scheduled task that **returns inventory held by abandoned (unpaid)
carts** — without it, a started-but-never-paid checkout keeps its reserved tickets
forever and an event can look "sold out" with no real sales. Add ONE cron entry in
cPanel → *Cron Jobs* (every minute; Laravel decides what actually runs):

```
* * * * * cd ~/droprsvp && php artisan schedule:run >> /dev/null 2>&1
```

That fires `orders:release-stale` every 10 minutes. Nothing else depends on the
scheduler today, but any future recurring job will run from the same single cron.

## Go-live checklist (confirm before real traffic)

These are **server-side settings**, not code — the app is otherwise clean
(ship-gate: 0 critical, 0 open high). Tick each on the production box:

- [ ] **`.env` is production-safe** — `APP_ENV=production`, `APP_DEBUG=false`
      (with debug on, an error leaks a full stack trace to visitors), `APP_URL`
      = your live `https://` domain.
- [ ] **Payments are live** — `CHIP_DRIVER=chip` + a live `CHIP_SECRET_KEY`
      (+ `CHIP_BRAND_ID`; `CHIP_PUBLIC_KEY` optional, auto-fetched). With the key
      blank the app falls back to the fake gateway — never ship that.
- [ ] **SSL valid** on the domain (cPanel → SSL/TLS Status / AutoSSL) and the
      site is reachable over `https://` (the app force-redirects to HTTPS in prod).
- [ ] **Scheduler cron added** — the `* * * * * … schedule:run` line above
      (runs the abandoned-cart reaper; without it, reserved stock never frees).
- [ ] **Mail is real** — `MAIL_MAILER=smtp` + SMTP creds (tickets, refund and
      verification emails go out over this; `log` only writes to a file).
- [ ] **(Recommended) Error monitoring on** — create a free Laravel project at
      sentry.io and set `SENTRY_LARAVEL_DSN=` in `.env`. It's wired and inert
      until you paste a DSN. Confirm it works: `php artisan sentry:test`.

## Verify (no browser needed)

```bash
curl -s https://yourdomain.com/ | grep -o '<title>[^<]*'         # server-rendered title
curl -s https://yourdomain.com/ | grep -o '"@type":"WebSite"'    # JSON-LD present
curl -s https://yourdomain.com/sitemap.xml | head                # sitemap
curl -sI https://yourdomain.com/build/manifest.json              # assets 200, not 404
curl -sI https://yourdomain.com/up                               # health check → 200
```
