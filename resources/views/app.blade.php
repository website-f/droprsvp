<!DOCTYPE html>
{{-- The site's content locale is Malaysian English (matches the /en-my URL scheme); emit BCP-47 en-MY for SEO. --}}
<html lang="en-MY" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Third-party analytics — PUBLIC pages only.
             These used to render on every page, so the GA property was measuring
             the back office: the admin user list, host screens, staff browsing.
             App\Support\Tracking decides; it also skips local/testing so dev
             traffic and the test suite never reach either service. --}}
        @php($track = \App\Support\Tracking::shouldTrack(request()))
        @php($trackingConfig = \App\Support\Tracking::clientConfig(request()))

        {{-- What the browser needs to switch the tags off again.
             The server not rendering them on /admin is only half the job: this
             is a SPA, so a visitor who arrives on a public page keeps the tag
             loaded and GA counts every later history change as a page view —
             panel screens included. TrackingGuard reads this and flips GA's
             own opt-out flag (and stops Clarity) before each navigation. --}}
        @if($trackingConfig)
            <script>window.__tracking = @json($trackingConfig);</script>
        @endif

        {{-- Google Analytics 4 (gtag.js). --}}
        @if($track && ($gaId = \App\Support\Tracking::measurementId()))
            <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
            <script>
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                gtag('js', new Date());

                gtag('config', @json($gaId));
            </script>
        @endif

        {{-- Microsoft Clarity (heatmaps + session replay).
             Same public-only scope as GA, and for a stronger reason: Clarity
             records the screen, so on an admin or host page it would be sending
             other people's names, emails and order history to a third party. --}}
        @if($track && ($clarityId = \App\Support\Tracking::clarityId()))
            <script type="text/javascript">
                (function(c,l,a,r,i,t,y){
                    c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                    t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                    y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
                })(window, document, "clarity", "script", @json($clarityId));
            </script>
        @endif

        {{-- Mark JavaScript as available before the body parses, so the
             server-rendered fallback inside #app is never painted for a visitor
             whose browser is about to replace it with the full app — no flash
             of plain text. Anything that does not run scripts (most crawlers)
             never gets the class and reads the content normally. --}}
        <script>document.documentElement.classList.add('js');</script>
        <style>
            .js .seo-fallback { display: none; }
            .seo-fallback { max-width: 48rem; margin: 0 auto; padding: 2rem 1.25rem; line-height: 1.6; color: #0a0a0a; }
            .seo-fallback h1 { font-size: 1.875rem; font-weight: 700; line-height: 1.2; margin: 0 0 .5rem; }
            .seo-fallback h2 { font-size: 1.25rem; font-weight: 600; margin: 1.75rem 0 .5rem; }
            .seo-fallback h3 { font-size: 1.05rem; font-weight: 600; margin: 1.25rem 0 .35rem; }
            .seo-fallback p, .seo-fallback ul, .seo-fallback ol, .seo-fallback dl { margin: .75rem 0; }
            .seo-fallback ul, .seo-fallback ol { padding-left: 1.25rem; list-style: disc; }
            .seo-fallback dt { font-weight: 600; margin-top: .5rem; }
            .seo-fallback dd { margin: 0; }
            .seo-fallback a { text-decoration: underline; }
            .seo-fallback img { max-width: 100%; height: auto; }
            .seo-fallback table { border-collapse: collapse; width: 100%; }
            .seo-fallback th, .seo-fallback td { border: 1px solid #ddd; padding: .4rem .6rem; text-align: left; }
        </style>

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #ffffff;
            }

            html.dark {
                background-color: #0a0a0a;
            }
        </style>

        @php($brandMark = \App\Support\SiteContent::branding()['logo_mark'] ?? '/logo-mark.png')
        <link rel="icon" href="{{ $brandMark }}" type="image/png">
        <link rel="apple-touch-icon" href="{{ $brandMark }}">

        {{-- Inter is now self-hosted (bundled via @fontsource in resources/css/app.css),
             so there's no render-blocking external font request. --}}

        {{-- Server-rendered SEO: title, meta, Open Graph, Twitter, canonical,
             robots and JSON-LD — emitted by Laravel so crawlers get everything
             before any JavaScript runs (no Node/SSR needed). --}}
        {!! app(\App\Support\SeoManager::class)->render() !!}

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head />
    </head>
    <body class="font-sans antialiased">
        {{-- The page's content, server-rendered INSIDE the React mount point.
             There is no Node/SSR in production, so without this a crawler that
             does not run JavaScript gets an empty #app. It used to sit in a
             <noscript>, which the HTML-to-text extractors AI tools and SEO
             crawlers use simply throw away — so pages still read as blank.
             React replaces this on mount (no hydration; see InertiaApp). --}}
        @php($seoBody = app(\App\Support\SeoManager::class)->crawlableHtml())
        <x-inertia-app>
            @if($seoBody)
                <div class="seo-fallback">{!! $seoBody !!}</div>
            @endif
        </x-inertia-app>
    </body>
</html>
