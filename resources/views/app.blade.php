<!DOCTYPE html>
{{-- The site's content locale is Malaysian English (matches the /en-my URL scheme); emit BCP-47 en-MY for SEO. --}}
<html lang="en-MY" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Google tag (gtag.js) — site-wide analytics. Skipped on local/testing so
             dev traffic and the test suite never pollute the GA property. --}}
        @unless(app()->environment('local', 'testing'))
            <script async src="https://www.googletagmanager.com/gtag/js?id=G-Q80TSQSFQ1"></script>
            <script>
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                gtag('js', new Date());

                gtag('config', 'G-Q80TSQSFQ1');
            </script>
        @endunless

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
