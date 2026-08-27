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
3. Expose ONLY the public assets (symlinks — they point outside the web root's
   own tree, so source stays private):
   ```bash
   cd ~/public_html
   ln -s ../droprsvp/public/build   build
   ln -s ../droprsvp/public/storage storage      # storage:link makes public/storage first
   ln -s ../droprsvp/public/og-default.png og-default.png
   ln -s ../droprsvp/public/logo.png        logo.png
   ln -s ../droprsvp/public/favicon.ico     favicon.ico
   ln -s ../droprsvp/public/favicon.svg     favicon.svg
   ln -s ../droprsvp/public/apple-touch-icon.png apple-touch-icon.png
   ```
   (If your host disallows symlinks, `cp -r` the same files instead and re-copy
   `build` after each deploy.)
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

# Payments — go live by switching the driver + keys
HITPAY_DRIVER=hitpay
HITPAY_API_KEY=...
HITPAY_SALT=...

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

---

## Update routine

```bash
cd ~/droprsvp   # or public_html/droprsvp
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache
# Option B with copies (not symlinks): also refresh assets:
# rm -rf ~/public_html/build && cp -r public/build ~/public_html/build
```

## Verify (no browser needed)

```bash
curl -s https://yourdomain.com/ | grep -o '<title>[^<]*'         # server-rendered title
curl -s https://yourdomain.com/ | grep -o '"@type":"WebSite"'    # JSON-LD present
curl -s https://yourdomain.com/sitemap.xml | head                # sitemap
curl -sI https://yourdomain.com/build/manifest.json              # assets 200, not 404
```
