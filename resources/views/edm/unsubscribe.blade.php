{{--
    Unsubscribe page for campaign email.

    GET shows this with a button; only the POST unsubscribes. Mail scanners and
    link previewers fetch every URL in an email, so a GET that acted would
    unsubscribe people who never clicked.
--}}
<!DOCTYPE html>
<html lang="en-MY">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $done ? 'You are unsubscribed' : 'Unsubscribe' }} · {{ config('seo.site_name', 'DropRSVP') }}</title>
    <style>
        :root { color-scheme: light dark; }
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
            background: #111827; color: #fff; font: inherit; font-weight: 600; text-decoration: none;
        }
        .muted { font-size: 13px; color: #6b7280; margin: 18px 0 0; }
        .muted a { color: inherit; }
        @media (prefers-color-scheme: dark) {
            body { background: #0a0a0a; color: #fafafa; }
            .card { background: #141414; border-color: #262626; }
            p, .muted { color: #a1a1aa; }
            button, .link { background: #fafafa; color: #111827; }
        }
    </style>
</head>
<body>
<main class="card">
    @if (! $valid)
        <h1>This link has expired</h1>
        <p>We couldn't find the email this link came from. You can manage every email preference from your account instead.</p>
        <a class="link" href="{{ url('/settings/notifications') }}">Email preferences</a>
    @elseif ($done)
        <h1>You're unsubscribed</h1>
        <p>You won't receive marketing emails from {{ $sender }} again. Tickets, receipts and messages about events you've booked still arrive as usual.</p>
        <p class="muted">Changed your mind? <a href="{{ url('/settings/notifications') }}">Turn them back on</a> any time.</p>
    @else
        <h1>Unsubscribe?</h1>
        <p>You'll stop receiving marketing emails from {{ $sender }}. Tickets and receipts are not affected.</p>
        <form method="post" action="{{ route('edm.unsubscribe.confirm', ['token' => $token]) }}">
            @csrf
            <button type="submit">Unsubscribe</button>
        </form>
    @endif
</main>
</body>
</html>
