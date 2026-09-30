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
        {{-- Crawlable content fallback: since there's no Node/SSR in production,
             content pages (help, blog…) render their body server-side here so
             non-JS crawlers index the actual text, not just the meta tags. Real
             visitors get the React version and never see this. --}}
        @php($seoBody = app(\App\Support\SeoManager::class)->crawlableHtml())
        @if($seoBody)
            <noscript>{!! $seoBody !!}</noscript>
        @endif
        <x-inertia::app />
    </body>
</html>
