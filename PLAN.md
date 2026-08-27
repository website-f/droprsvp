# DropRSVP — Platform Plan & Architecture

Event ticketing & management platform (Eventbrite feature-set) with a built-in
headless CMS (WordPress-style), wearing the **Meetup UI/UX** with the **Tiratech
colour theme**. Built on **Laravel + React** with server-side rendering for SEO.

> Client: **My Hub Solution Enterprise**. Repo: `droprsvp/`. Design tokens: `DESIGN.md`.

---

## 1. Product in one line

Hosts create events → sell multi-tier tickets → manage seats/tables, attendees,
check-in and payouts from a beautiful panel; the public discovers and buys via a
fast, SEO-friendly marketplace; the platform owner (superadmin) runs the whole
thing **and** publishes marketing pages/blog through an in-house CMS.

---

## 2. Key decisions (locked unless flagged)

| # | Decision | Choice | Why |
|---|----------|--------|-----|
| D1 | Rendering | **Inertia.js v2 (React) + SSR** | One codebase, Laravel-driven routing, server-rendered HTML per page → SEO solved without a separate Next.js runtime. Rich React UI for the seat map / editor. |
| D2 | Styling | **Tailwind CSS** driven by `DESIGN.md` tokens | Meetup type/spacing/shape (pill buttons); **Tiratech colours = rich black & white monochrome** (done in `app.css`, red kept only for errors). |
| D3 | Rich text | **TipTap** (ProseMirror) | Self-hosted, React-native, clean HTML+JSON output, extensible → WordPress-like editing, no external CDN. |
| D4 | Payments | **HitPay** (behind a gateway abstraction) | Same gateway as qrpos/portalkahwin; webhooks + refunds; driver stays swappable. |
| D5 | DB | **MySQL 8** + **Redis** (cache, queues, sessions) | Same as existing stack; queues critical for ticketing spikes. |
| D6 | Search/discovery | MySQL full-text + filters first; **Meilisearch** optional later | Avoid premature infra; add Meili when catalogue grows. |
| D7 | Deploy | **Docker Compose behind the shared `/opt/reverse-proxy`** (own port block) | Matches VPS convention; one TLS terminator. |

**Confirmed:** colours = **rich black & white** (Tiratech); payments = **HitPay**.
**Still open:** whether recurring/multi-session events are in v1, and SST/tax handling.

---

## 3. Tech stack

- **Backend:** Laravel 11/13, PHP 8.3, Eloquent, Queues (Horizon), Scheduler, Sanctum (API tokens), Spatie Permission (RBAC), Spatie MediaLibrary (uploads), Laravel Excel (exports), DomPDF/Browsershot (tickets/receipts).
- **Frontend:** React 19 + TypeScript, **Inertia v2**, Vite, Tailwind, TipTap, `@dnd-kit` (seat-map + CMS block ordering), Recharts (dashboards), Ziggy (routes in JS).
- **SSR:** Inertia SSR (Node side-process) for public + content pages; panel pages can skip SSR.
- **Infra:** MySQL 8, Redis, S3-compatible object storage (or local + backup), Meilisearch (optional), Mailer (SMTP/relay), Docker, Caddy (shared reverse proxy).

---

## 4. High-level architecture

```
                          ┌───────────────────────────────────────────┐
                          │        Shared Caddy (/opt/reverse-proxy)     │
                          │        droprsvp.<domain>  → app:PORT         │
                          └───────────────────┬─────────────────────────┘
                                              │
        ┌──────────────────────── Laravel (PHP-FPM/Octane) ───────────────────────┐
        │  HTTP  ├─ Web routes → Inertia pages (SSR) ── public + panel + admin     │
        │        ├─ /api/*     → JSON API (mobile, widgets, webhooks)              │
        │  Domain├─ Services: Ticketing, Orders, Payments, Payouts, Seating,       │
        │        │            CheckIn, CMS, SEO, Notifications                      │
        │  Jobs  ├─ Queues (Horizon): payment settle, email/SMS, payout batch,     │
        │        │            sitemap build, exports, webhook retries              │
        │  Data  └─ MySQL 8 · Redis · Object storage · (Meilisearch)               │
        └──────────────────────────────────────────────────────────────────────────┘
                     │                         │
             Inertia SSR (Node)          Payment gateway (webhooks)
```

---

## 5. Rendering & SEO strategy

- **Public pages SSR** (marketplace, event detail, organizer profile, blog, CMS pages): server-rendered HTML + per-page `<title>/meta/canonical/OG`, hydrate with React.
- **Structured data (JSON-LD):** `schema.org/Event` (name, dates, location, offers/price, availability) on event pages; `Article`/`BlogPosting` on posts; `BreadcrumbList`; `Organization`. → Google rich results.
- **Sitemaps:** auto-generated `sitemap.xml` (events, organizers, CMS pages, posts), rebuilt on publish via a queued job; `robots.txt`; canonical + `noindex` for private/draft.
- **Performance:** image transforms + `srcset`, lazy loading, code-split panel bundles, cache public pages (Redis/edge), Core Web Vitals budget per route.
- **Panel/admin:** no SEO needed → SSR optional, behave as an app.

---

## 6. Roles & permissions (Spatie)

| Role | Scope |
|------|-------|
| **superadmin** (client / My Hub) | Everything: platform settings, all events/orders, payouts, users, **CMS**, fees, reports. |
| **organizer / host** | Own events: create/manage events, tickets, seating, attendees, check-in staff, view own sales & request payout. |
| **staff / scanner** | Check-in only, scoped to assigned event(s). |
| **attendee / buyer** | Buy tickets, buyer account, orders, tickets/QR, refunds request. |
| guest | Browse marketplace, view events, start checkout. |

---

## 7. Domain model (core tables)

**Identity & org**
- `users` (role via pivot), `organizers` (profile: name, logo, bio, payout info, slug), `organizer_user` (team membership + role), `staff_assignments`.

**Events**
- `events` (organizer_id, title, slug, description, cover, category_id, status[draft/published/cancelled], visibility[public/unlisted/private], timezone, is_online, venue fields, capacity, seo meta)
- `event_sessions` (for recurring/multi-session: starts_at, ends_at, capacity) — *v1 optional*
- `event_categories`, `event_tags`, `event_images`.

**Ticketing**
- `ticket_types` (event_id, name, kind[paid/free/donation], price, currency, quantity, min/max per order, sales_start, sales_end, visibility, sort)
- `ticket_type_discounts` link to `discount_codes`.
- `discount_codes` (code, type[percent/amount], value, max_uses, used, per_user_limit, starts/ends, scope[event/global])
- `waitlist_entries`.

**Seating (assigned events)**
- `venues`, `seating_charts` (event/venue), `sections`, `tables`, `seats` (row/number, x/y, status), `seat_holds` (short TTL during checkout), `seat_assignments` (→ ticket).

**Orders & tickets**
- `orders` (buyer_id, event_id, status[pending/paid/refunded/failed], subtotal, discount, fees, tax, total, currency, payment_ref, meta)
- `order_items` (order_id, ticket_type_id, qty, unit_price)
- `tickets` (order_item_id, attendee fields, seat_assignment_id, **qr_token**, status[valid/checked_in/void/refunded], checked_in_at, checked_in_by)
- `refunds` (order_id, amount, reason, status).

**Money out**
- `payouts` (organizer_id, period, gross, platform_fee, net, status, method, attachment/ref)
- `payout_lines` (order → payout), `platform_fees` (config), `transactions` (audit ledger).

**Check-in**
- reads `tickets.qr_token`; `check_in_logs` (ticket_id, event_id, staff_id, at, device).

**Headless CMS**
- `cms_pages` (title, slug, blocks(JSON)/html, status, template, seo meta, published_at)
- `cms_posts` (title, slug, excerpt, body(JSON/html), cover, author_id, category_id, status, published_at, seo)
- `cms_categories`, `cms_tags`, `cms_post_tag`
- `cms_media` (Spatie MediaLibrary), `cms_menus` + `cms_menu_items` (nav builder)
- `cms_redirects` (301/302), `revisions` (polymorphic version history).

**SEO & system**
- `seo_meta` (polymorphic → any page/post/event: `seo_title`, `meta_description`, `slug`, `focus_keyphrase`, `canonical_url`, `robots_index`, `robots_follow`, `og_title`, `og_description`, `og_image`, `twitter_card`, `breadcrumb_title`, `schema_type`, `schema_overrides` JSON)
- `settings` (platform config: fees, currency, branding), `audit_logs`, `notifications`.

---

## 8. Modules & feature breakdown

**A. Public marketplace (Meetup look, Tiratech colours)**
- Home/discovery: search, filter (category, date, location, price, online/in-person), featured, "near you".
- Event detail (SSR + `Event` JSON-LD): hero, description, dates, venue/map, ticket picker, organizer card, share.
- Organizer public profile page. CMS-driven landing/marketing pages + blog.

**B. Ticketing & checkout (Eventbrite flow)**
- Select ticket types + qty (respect min/max, inventory, sales window) → apply discount code → (optional) pick seats/table → attendee details → **payment** → order confirmation → email tickets w/ QR.
- Inventory integrity: reserve/hold with TTL (Redis + `seat_holds`), release on abandon; oversell protection via DB locking in a queued/transactional path.
- Free events → RSVP flow (no payment). Donation/pay-what-you-want optional.

**C. Host / Organizer panel** *(the "nicely designed Meetup-theme panel")*
- Event builder (details, images, categories, SEO).
- **Ticket manager** (types, prices, inventory, codes, waitlist).
- **Seat & table management**: visual chart builder (drag sections/tables/seats), capacity, assign/lock, buyer-select or host-assign modes.
- Orders & attendees: list, search, filter, resend tickets, refund, export CSV/Excel.
- **Check-in**: QR scanner (camera), manual search, multi-staff, live count.
- **Dashboard**: sales over time, revenue, tickets sold by type, conversion, payout balance (Recharts).
- Payout request + history.

**D. Superadmin panel (client / My Hub)**
- All of the above across every organizer + platform controls: fees/commission, currencies, categories, users & roles, organizer approval, refunds oversight, **payouts processing**, global reports, feature flags, branding/settings, **CMS** (below).

**E. Headless CMS (WordPress-like, superadmin)**
- **Pages**: create/edit with **TipTap** rich editor (+ optional block layout), templates, hierarchy, publish/schedule/draft, preview.
- **Blog**: posts, categories, tags, authors, featured image, excerpt, scheduling.
- **Media library**, **menu/nav builder**, **redirects**, **revisions/version history**.
- **Per-item SEO panel — WordPress/Yoast-style, on every page & post:**
  editable **SEO title**, **meta description**, **URL slug**, **focus keyphrase**,
  **canonical URL**, **robots** (index/noindex, follow/nofollow), **Open Graph**
  (title/description/image) + **Twitter card**, **breadcrumb title**, **schema type**,
  with a **live Google snippet preview** + **social share preview** and a simple
  content/SEO check. Falls back to the page title/excerpt when a field is blank.
- Output: pages/posts served **SSR** at clean URLs → the SEO title/meta/OG/canonical/
  robots/JSON-LD are rendered into the real server HTML (not client-injected), and the
  sitemap rebuilds on publish.

**F. Notifications**
- Email (order confirmation + tickets, reminders, payout notices, refunds), optional SMS/WhatsApp for reminders/passes. Templated, queued.

---

## 9. Design system integration

- Generate `tailwind.config.ts` + CSS variables from `DESIGN.md`:
  - **Keep**: typography (NeuSans/Inter scale), spacing, radii (pill buttons), elevation, layout grid, component specs (button/card/input/badge/search).
  - **Replace**: all `colors.*` tokens with **Tiratech** palette (primary/secondary/surfaces/on-*). *Blocked on Tiratech hex.*
- Build a shared component library (`resources/js/ui/`) implementing the DESIGN.md components once; the panel + public site both consume it → consistent Meetup feel everywhere.
- Respect DESIGN.md do/don'ts (primary only on CTAs/active, generous 40–64px section spacing, pill radius).

---

## 10. Repository structure

```
droprsvp/
├─ app/
│  ├─ Models/                 # Event, TicketType, Order, Ticket, Payout, CmsPage, CmsPost, ...
│  ├─ Http/Controllers/
│  │   ├─ Public/             # Marketplace, EventShow, OrganizerShow, Cms(Page|Post)
│  │   ├─ Checkout/           # Cart, Payment, Order
│  │   ├─ Host/               # Events, Tickets, Seating, Attendees, CheckIn, Payouts, Dashboard
│  │   ├─ Admin/              # Platform settings, Users, Payouts, Reports
│  │   ├─ Cms/                # Pages, Posts, Media, Menus, Redirects (superadmin)
│  │   └─ Api/                # JSON API + webhooks
│  ├─ Services/               # Ticketing, Orders, Payments, Payouts, Seating, CheckIn, Seo, Cms
│  ├─ Jobs/  Policies/  Support/
├─ resources/js/
│  ├─ Pages/                  # Inertia pages: Public/*, Checkout/*, Host/*, Admin/*, Cms/*
│  ├─ ui/                     # DESIGN.md component library (Button, Card, Input, Badge, ...)
│  ├─ features/               # seat-map, ticket-picker, editor(TipTap), dashboard-charts
│  ├─ layouts/                # PublicLayout, PanelLayout(Meetup theme), AdminLayout
│  └─ lib/  ssr.tsx  app.tsx
├─ routes/ web.php  api.php  channels.php
├─ database/ migrations/  seeders/  factories/
├─ config/ droprsvp.php (fees, currency, features)
├─ tests/ Feature/  Unit/
├─ docker/  docker-compose.yml  Dockerfile  Caddyfile(snippet)
├─ DESIGN.md   PLAN.md   README.md
```

---

## 11. Routing map

- **Public:** `/` (discovery), `/events`, `/e/{slug}` (event), `/o/{slug}` (organizer), `/blog`, `/blog/{slug}`, `/{page-slug}` (CMS pages), `/search`.
- **Checkout:** `/e/{slug}/tickets` → `/checkout` → `/checkout/pay` → `/order/{id}` (+ `/checkout/return` webhook/redirect).
- **Buyer:** `/account`, `/account/orders`, `/tickets/{token}` (QR pass).
- **Host panel:** `/host` (dashboard), `/host/events...`, `/host/events/{id}/{tickets|seating|orders|attendees|checkin|settings}`, `/host/payouts`.
- **Admin:** `/admin/...` (users, organizers, payouts, fees, reports, settings).
- **CMS:** `/admin/cms/{pages|posts|media|menus|redirects}`.
- **API/webhooks:** `/api/v1/*`, `/webhooks/payment`.

---

## 12. Non-functional

- **Security:** RBAC policies on every host/admin action; ownership scoping (organizer can't touch others' data); signed QR tokens; rate limiting on checkout/scan; webhook signature verification; CSRF; audit logs; secrets in env.
- **Payments integrity:** idempotent payment settle, DB-transactional inventory decrement, seat holds w/ TTL, reconcile via webhook, ledger table.
- **Performance/scale:** Octane optional, Redis cache for public pages + inventory counters, queue heavy work (email, payouts, exports, sitemap), read-friendly queries + indexes, CDN for media.
- **Testing:** feature tests for checkout/inventory/refunds/permissions; unit tests for fee/seat math; seed + factory data.
- **i18n:** EN + BM ready (dict layer), currency = MYR default.
- **Accessibility:** keyboard + focus states per DESIGN.md, semantic HTML.

---

## 13. Infrastructure & deployment

- **Docker Compose:** `app` (php-fpm/octane), `web` (nginx or octane), `queue` (Horizon), `scheduler`, `mysql`, `redis`, `ssr` (node inertia), `meili` (optional).
- **Reverse proxy:** register `droprsvp.<domain>` in the shared `/opt/reverse-proxy` Caddy with its own port block (per VPS convention); no second TLS terminator.
- **Env/build:** `VITE_*` are build-time → rebuild image on frontend env change (don't just recreate). Read the project's own `deploy.sh` before manual compose.
- **Backups:** DB (mysqldump/binlog) + media → offsite (restic/B2), tested restore.
- **Observability:** Horizon dashboard, Sentry (optional), request/error logs.

---

## 14. Roadmap (phased)

**P0 — Foundation (setup)**
Laravel + Inertia + React + Tailwind scaffold; **Tiratech colour tokens** from DESIGN.md; UI component library; auth + RBAC (superadmin/host/staff/buyer); Docker + reverse-proxy + CI; base layouts (Public / Panel / Admin).

**P1 — Ticketing core**
Events CRUD, ticket types + inventory, discount codes, checkout + payment gateway + webhooks, orders, tickets + QR email, order confirmation. Free-event RSVP path.

**P2 — Host panel**
Dashboard (charts), ticket manager, **seat & table builder + checkout seat selection**, attendees + orders + refunds + CSV export, **check-in scanner** (multi-staff), payout request.

**P3 — Public marketplace + SEO**
Discovery/search/filters, event & organizer pages (SSR + `Event` JSON-LD), buyer accounts, sitemaps + OG previews, performance pass.

**P4 — Headless CMS**
Pages + blog with TipTap, media library, menus, redirects, revisions, per-item SEO, SSR content routes + `Article` schema + sitemap.

**P5 — Superadmin + payouts + hardening**
Platform settings/fees, organizer approval, **payout processing**, global reports, refunds oversight, security & load hardening, QA, launch.

---

## 15. Immediate next steps

1. **Confirm Tiratech palette** (hex or logo/site) → generate Tailwind colour tokens.
2. Confirm **payment gateway** + whether recurring/multi-session events are in v1.
3. Scaffold **P0** (repo skeleton, stack, tokens, layouts, RBAC).
4. Lock the **data-model migrations** for P1 and start the ticketing core.
```
