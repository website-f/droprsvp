<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $title ?? config('app.name') }}</title>
    <style>
        /* Keep it simple — most clients strip <style>, so the important bits are inlined below. */
        a { color: #6c63ff; }
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; }
            .px { padding-left: 22px !important; padding-right: 22px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#f4f4f6;-webkit-text-size-adjust:100%;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
    {{-- Preheader: the grey preview snippet in the inbox list. --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preheader ?? '' }}&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f6;">
        <tr>
            <td align="center" style="padding:28px 16px;">
                <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;">

                    {{-- Brand --}}
                    <tr>
                        <td align="center" style="padding:4px 0 20px;">
                            <span style="font-size:22px;font-weight:800;letter-spacing:-.02em;color:#18181b;">Drop<span style="color:#6c63ff;">RSVP</span></span>
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td class="px" style="background:#ffffff;border:1px solid #e7e7ea;border-radius:16px;padding:32px;">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td class="px" style="padding:22px 32px 8px;text-align:center;color:#8a8a92;font-size:12px;line-height:1.6;">
                            @hasSection('footnote')
                                <p style="margin:0 0 10px;">@yield('footnote')</p>
                            @endif
                            <p style="margin:0 0 4px;">{{ config('app.name') }} · powered by My Hub Solution Enterprise</p>
                            <p style="margin:0;">
                                Need help? <a href="{{ url('/help') }}" style="color:#8a8a92;text-decoration:underline;">Help centre</a>
                                &nbsp;·&nbsp; <a href="{{ url('/contact') }}" style="color:#8a8a92;text-decoration:underline;">Contact us</a>
                            </p>
                            <p style="margin:10px 0 0;">You’re receiving this email because you have an account or a booking on {{ config('app.name') }}.</p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
