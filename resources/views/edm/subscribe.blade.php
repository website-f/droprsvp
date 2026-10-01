{{--
    "Yes, keep me posted" — the answer to the one-off re-permission email.

    GET shows this with a button; only the POST records consent. Mail scanners
    open every link in an email, and consent recorded on their visit would be
    consent nobody gave.
--}}
<!DOCTYPE html>
<html lang="en-MY">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $done ? 'You are in' : 'Stay in the loop?' }} · {{ config('seo.site_name', 'DropRSVP') }}</title>
    <style>
        :root { color-scheme: light dark; }
        /* Without this, width:100% plus the card's padding is wider than a phone. */
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
            background: #f3f4f6; color: #111827;
            font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .card { max-width: 440px; width: 100%; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 32px; text-align: center; }
        h1 { margin: 0 0 8px; font-size: 21px; }
        p { margin: 0 0 20px; color: #4b5563; }
        button, .link {
            display: inline-block; padding: 11px 22px; border-radius: 999px; border: 0; cursor: pointer;
            background: #6d28d9; color: #fff; font: inherit; font-weight: 600; text-decoration: none;
        }
        .muted { font-size: 13px; color: #6b7280; margin: 18px 0 0; }
        .muted a { color: inherit; }
        @media (prefers-color-scheme: dark) {
            body { background: #0a0a0a; color: #fafafa; }
            .card { background: #141414; border-color: #262626; }
            p, .muted { color: #a1a1aa; }
        }
    </style>
</head>
<body>
<main class="card">
    @if (! $valid)
        <h1>This link has expired</h1>
        <p>You can choose which emails you get from your account settings instead.</p>
        <a class="link" href="{{ url('/settings/notifications') }}">Email preferences</a>
    @elseif ($done)
        <h1>You're in</h1>
        <p>We'll email you now and then about upcoming events. Every email has a one-click unsubscribe at the bottom.</p>
        <a class="link" href="{{ url('/en-my/all/') }}">Browse events</a>
    @else
        <h1>Stay in the loop?</h1>
        <p>We'd like to email you now and then about events on DropRSVP — only if you want us to.</p>
        <form method="post" action="{{ route('edm.subscribe.confirm', ['token' => $token]) }}">
            @csrf
            <button type="submit">Yes, keep me posted</button>
        </form>
        <p class="muted">Not interested? Just close this page. We won't ask again.</p>
    @endif
</main>
</body>
</html>
