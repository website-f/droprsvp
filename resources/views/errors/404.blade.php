{{--
    Production 404 page.

    Laravel's default is a bare "404 | Not Found" on a white page, which tells a
    visitor nothing and offers them nowhere to go — so a mistyped or expired
    event link was simply a dead end. This gives them the three things they
    might actually want next (browse events, host one, get help) and states the
    URL that failed, which is the first thing anyone reports.

    Deliberately self-contained: no Vite assets, no Inertia, no database. An
    error page that depends on the app is an error page that can fail too.
--}}
@php
    use App\Support\Cities;
    use App\Support\Url;

    $site = config('seo.site_name', 'DropRSVP');
    $home = Url::to();
    $browse = Url::to(Cities::ANY);
    $help = Url::to('help');
    // Never the raw query string: it can carry whatever was in the link.
    $requested = '/'.ltrim(request()->path(), '/');

    // The hero photo. A PHOTOGRAPH, not og-default.png — that is the social
    // share card with "Find your people. Fill your events." set into it, and
    // using it here printed that headline through the 404 copy.
    //
    // Hardcoded on purpose: an error page should not need the database to
    // render, least of all when the thing that broke might be the database. If
    // the file is ever removed the layered background falls through to the
    // gradient underneath it, which stands on its own.
    $heroImage = asset('storage/cms/ZEr1wBEmpjVFWZkhF9MK7THDTQuv2u2HK0AtxbfZ.jpg');
@endphp
<!DOCTYPE html>
<html lang="en-MY">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- follow, so the links out of here still pass value; noindex, because a
         404 must never appear in results. --}}
    <meta name="robots" content="noindex, follow">
    <title>Page not found | {{ $site }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: #6d28d9;
            --brand-dark: #5b21b6;
            --brand-soft: #ede9fe;
            --accent: #f97316;
            --ink: #111827;
            --ink-2: #374151;
            --muted: #6b7280;
            --line: #e5e7eb;
            --bg: #ffffff;
            --bg-alt: #f3f4f6;
            --panel: #e9e9ec;
            --radius: 14px;
            --max: 1240px;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: var(--ink);
            background: var(--bg);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }
        /* Inherit, so nothing ever falls back to the browser's default link blue. */
        a { color: inherit; text-decoration: none; }
        img, svg { display: block; max-width: 100%; }
        .wrap { max-width: var(--max); margin: 0 auto; padding: 0 24px; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 22px; border-radius: 999px;
            font-weight: 600; font-size: 15px; transition: .2s;
        }
        .btn-white { background: #fff; color: var(--ink); }
        .btn-white:hover { background: var(--brand-soft); }
        .btn-ghost { border: 1.5px solid rgba(255,255,255,.7); color: #fff; }
        .btn-ghost:hover { background: rgba(255,255,255,.12); }

        /* ───────── Hero ───────── */
        .hero {
            position: relative; min-height: 512px;
            display: flex; align-items: center; color: #fff; overflow: hidden;
            background:
                linear-gradient(90deg, rgba(17,10,40,.88) 0%, rgba(17,10,40,.6) 45%, rgba(17,10,40,.15) 100%),
                url("{{ $heroImage }}") center/cover no-repeat,
                linear-gradient(135deg, #2e1065 0%, #6d28d9 55%, #f97316 100%);
        }
        .hero-deco { position: absolute; inset: 0; pointer-events: none; }
        .hero-deco span {
            position: absolute; width: 56px; height: 32px; border-radius: 6px;
            background: rgba(255,255,255,.12); border: 1px dashed rgba(255,255,255,.35);
            animation: float 9s ease-in-out infinite;
        }
        .hero-deco span:nth-child(1) { top: 16%; right: 12%; transform: rotate(-14deg); }
        .hero-deco span:nth-child(2) { top: 60%; right: 26%; transform: rotate(10deg); animation-delay: -3s; }
        .hero-deco span:nth-child(3) { top: 30%; right: 38%; transform: rotate(22deg); animation-delay: -6s; width: 40px; height: 24px; }
        @keyframes float { 0%,100% { translate: 0 0; } 50% { translate: 0 -16px; } }

        .hero-content { position: relative; max-width: 620px; padding: 72px 0; }
        .hero-badge {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 13px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;
            background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.25);
            padding: 6px 12px; border-radius: 999px; margin-bottom: 20px;
        }
        .hero h1 { font-size: clamp(30px, 4.4vw, 46px); line-height: 1.12; margin: 0 0 16px; font-weight: 800; letter-spacing: -.02em; }
        .hero p.lead { font-size: 18px; margin: 0 0 12px; color: rgba(255,255,255,.92); }
        .hero .code { font-size: 14px; color: rgba(255,255,255,.7); margin: 0 0 28px; word-break: break-all; }
        .hero .code code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .hero-actions { display: flex; flex-wrap: wrap; gap: 12px; }

        /* ───────── Cards ───────── */
        .cards { padding: 24px 0 56px; }
        .card-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 40px; }
        .card { display: flex; flex-direction: column; }
        .card-media {
            height: 216px; border-radius: var(--radius); background: var(--panel);
            display: grid; place-items: center; overflow: hidden; margin-bottom: 36px;
            transition: transform .25s ease, box-shadow .25s ease;
        }
        .card:hover .card-media { transform: translateY(-4px); box-shadow: 0 14px 30px rgba(17,24,39,.1); }
        .card h2 { font-size: 22px; font-weight: 800; margin: 0 0 8px; letter-spacing: -.01em; color: var(--ink); }
        .card p { font-size: 16px; color: var(--ink-2); margin: 0 0 14px; flex: 1; }
        /* Black, not the brand purple — asked for explicitly, and it keeps the
           one purple thing on the page (the hero) doing the work. */
        .card-link {
            align-self: flex-start; color: var(--ink); font-weight: 700; font-size: 14px;
            letter-spacing: .06em; text-transform: uppercase; padding: 4px 10px; margin-left: -10px;
            border-radius: 6px; display: inline-flex; align-items: center; gap: 6px;
        }
        .card-link:hover { background: var(--bg-alt); }
        .card-link svg { transition: transform .2s; }
        .card-link:hover svg { transform: translateX(3px); }

        /* ───────── Responsive ───────── */
        @media (max-width: 960px) { .card-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 720px) {
            .hero { min-height: 440px; }
            .hero-content { padding: 56px 0; }
            .card-grid { grid-template-columns: 1fr; gap: 36px; }
            .card-media { margin-bottom: 24px; }
        }
        @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }
    </style>
</head>
<body>
<main>
    <section class="hero" aria-labelledby="notFoundTitle">
        <div class="hero-deco" aria-hidden="true"><span></span><span></span><span></span></div>
        <div class="wrap">
            <div class="hero-content">
                <div class="hero-badge">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg>
                    Error 404
                </div>
                <h1 id="notFoundTitle">Sorry, this event has left the building.</h1>
                <p class="lead">The link may be misspelled, or the page you&rsquo;re looking for has ended, moved or is no longer available.</p>
                <p class="code">Requested URL: <code>{{ $requested }}</code></p>
                <div class="hero-actions">
                    <a href="{{ $browse }}" class="btn btn-white">Browse all events</a>
                    <a href="{{ $home }}" class="btn btn-ghost">Back to homepage</a>
                </div>
            </div>
        </div>
    </section>

    <section class="cards" aria-label="Helpful links">
        <div class="wrap card-grid">
            <article class="card">
                <a href="{{ $browse }}" class="card-media" aria-hidden="true" tabindex="-1">
                    <svg width="230" height="150" viewBox="0 0 230 150" fill="none">
                        <rect x="20" y="30" width="80" height="104" rx="10" fill="#fff" transform="rotate(-8 60 82)"/>
                        <rect x="26" y="36" width="68" height="46" rx="6" fill="#c4b5fd" transform="rotate(-8 60 82)"/>
                        <rect x="75" y="18" width="80" height="112" rx="10" fill="#fff" stroke="#e5e7eb"/>
                        <rect x="82" y="25" width="66" height="52" rx="6" fill="#6d28d9"/>
                        <circle cx="115" cy="51" r="12" fill="#f97316"/>
                        <rect x="84" y="86" width="50" height="6" rx="3" fill="#111827"/>
                        <rect x="84" y="98" width="36" height="5" rx="2.5" fill="#9ca3af"/>
                        <rect x="84" y="110" width="44" height="12" rx="6" fill="#ede9fe"/>
                        <rect x="138" y="30" width="78" height="100" rx="10" fill="#fff" transform="rotate(9 177 80)"/>
                        <rect x="144" y="36" width="66" height="44" rx="6" fill="#fdba74" transform="rotate(9 177 80)"/>
                    </svg>
                </a>
                <h2>Discover events</h2>
                <p>Concerts, festivals, workshops, conferences and meetups happening near you in Malaysia.</p>
                <a href="{{ $browse }}" class="card-link">Explore events
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
            </article>

            <article class="card">
                <a href="{{ route('organizer.start') }}" class="card-media" aria-hidden="true" tabindex="-1">
                    <svg width="220" height="150" viewBox="0 0 220 150" fill="none">
                        <rect x="40" y="22" width="140" height="112" rx="12" fill="#fff"/>
                        <rect x="40" y="22" width="140" height="30" rx="12" fill="#6d28d9"/>
                        <rect x="40" y="40" width="140" height="12" fill="#6d28d9"/>
                        <rect x="68" y="12" width="8" height="22" rx="4" fill="#111827"/>
                        <rect x="144" y="12" width="8" height="22" rx="4" fill="#111827"/>
                        <g fill="#e5e7eb">
                            <rect x="56" y="64" width="20" height="16" rx="4"/><rect x="84" y="64" width="20" height="16" rx="4"/>
                            <rect x="140" y="64" width="20" height="16" rx="4"/>
                            <rect x="56" y="88" width="20" height="16" rx="4"/><rect x="84" y="88" width="20" height="16" rx="4"/>
                            <rect x="112" y="88" width="20" height="16" rx="4"/><rect x="140" y="88" width="20" height="16" rx="4"/>
                            <rect x="56" y="112" width="20" height="12" rx="4"/><rect x="84" y="112" width="20" height="12" rx="4"/>
                        </g>
                        <rect x="112" y="64" width="20" height="16" rx="4" fill="#f97316"/>
                        <circle cx="176" cy="118" r="22" fill="#f97316"/>
                        <path d="M176 108v20M166 118h20" stroke="#fff" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                </a>
                <h2>Host your own event</h2>
                <p>Create an event page, share one link and collect RSVPs &mdash; free on {{ $site }}.</p>
                <a href="{{ route('organizer.start') }}" class="card-link">Start hosting
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
            </article>

            <article class="card">
                <a href="{{ $help }}" class="card-media" aria-hidden="true" tabindex="-1">
                    <svg width="96" height="96" viewBox="0 0 96 96" fill="none">
                        <circle cx="48" cy="48" r="44" stroke="#111827" stroke-width="5"/>
                        <path d="M36 37a12 12 0 1 1 17.5 10.6c-3.4 1.8-5.5 4.2-5.5 8v2.4" stroke="#111827" stroke-width="5.5" stroke-linecap="round"/>
                        <circle cx="48" cy="70" r="3.8" fill="#111827"/>
                    </svg>
                </a>
                <h2>{{ $site }} support</h2>
                <p>Have questions about tickets, RSVPs or hosting? Find answers or get in touch with our team.</p>
                <a href="{{ $help }}" class="card-link">Get help
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
            </article>
        </div>
    </section>
</main>
</body>
</html>
