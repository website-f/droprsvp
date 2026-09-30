{{--
    Production 500 page.

    Without this Laravel serves a near-blank document, and Inertia renders a
    failed response inside a white modal overlay — which is how a server error
    reached us as "a white screen comes out" with nothing to go on. This gives
    the person something to read and, more usefully, an ID to quote that matches
    the entry in storage/logs/laravel.log.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Something went wrong · DropRSVP</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            padding: 24px; background: #fafafa; color: #18181b;
            font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        @media (prefers-color-scheme: dark) { body { background: #0a0a0a; color: #fafafa; } .card { background: #141414; border-color: #262626; } }
        .card { max-width: 420px; width: 100%; background: #fff; border: 1px solid #e4e4e7; border-radius: 16px; padding: 28px; text-align: center; }
        h1 { margin: 0 0 8px; font-size: 19px; }
        p { margin: 0 0 18px; opacity: .75; }
        code { font-size: 12px; opacity: .6; word-break: break-all; }
        a { display: inline-block; margin-top: 4px; padding: 9px 18px; border-radius: 9px; background: #18181b; color: #fff; text-decoration: none; font-weight: 600; font-size: 14px; }
        @media (prefers-color-scheme: dark) { a { background: #fafafa; color: #18181b; } }
    </style>
</head>
<body>
    <div class="card">
        <h1>Something went wrong</h1>
        <p>We hit an error on our side. Nothing you did caused it, and your data is safe.</p>
        <a href="{{ url('/') }}">Back to DropRSVP</a>
        @if (! empty($exception) && method_exists($exception, 'getMessage'))
            {{-- Only an identifier, never the message: a stack trace on a public
                 page leaks paths, queries and sometimes credentials. --}}
            <p style="margin-top:18px"><code>Reference {{ substr(md5($exception->getFile().$exception->getLine()), 0, 8) }}</code></p>
        @endif
    </div>
</body>
</html>
