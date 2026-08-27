<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0a0a0a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">
        <tr>
            <td align="center" style="padding:28px 12px;">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;background:#ffffff;border:1px solid #e5e5e6;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td style="background:#111111;color:#ffffff;padding:22px 28px;">
                            <div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;opacity:.7;">DropRSVP · You're going!</div>
                            <div style="font-size:20px;font-weight:700;margin-top:6px;">{{ $event->title }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 28px;font-size:15px;line-height:1.6;">
                            <p style="margin:0 0 14px;">Hi {{ $order->buyer_name ?: 'there' }}, your tickets are confirmed.</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13.5px;color:#3f3f46;margin-bottom:8px;">
                                @if ($event->starts_at)
                                    <tr><td style="padding:3px 0;">🗓 {{ $event->starts_at->copy()->setTimezone($event->timezone)->format('D, j M Y · g:i A') }}</td></tr>
                                @endif
                                <tr><td style="padding:3px 0;">📍 {{ $event->is_online ? 'Online event' : ($event->venue_name ?: 'Venue TBC') }}</td></tr>
                                <tr><td style="padding:3px 0;">🔖 Order {{ $order->reference }} · Total RM {{ number_format((float) $order->total, 2) }}</td></tr>
                            </table>

                            <div style="border-top:1px solid #e5e5e6;margin:18px 0;"></div>

                            <div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#6b6b70;margin-bottom:10px;">Your {{ $tickets->count() }} ticket(s)</div>

                            @foreach ($tickets as $ticket)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e5e6;border-radius:10px;margin-bottom:10px;">
                                    <tr>
                                        <td style="padding:14px 16px;">
                                            <div style="font-weight:600;">{{ $ticket->attendee_name ?: 'Guest' }}</div>
                                            <div style="font-size:12px;color:#6b6b70;">{{ optional($ticket->ticketType)->name }}</div>
                                        </td>
                                        <td align="right" style="padding:14px 16px;">
                                            <a href="{{ route('tickets.show', $ticket) }}" style="display:inline-block;background:#111111;color:#ffffff;text-decoration:none;font-size:13px;font-weight:600;padding:10px 18px;border-radius:999px;">View ticket</a>
                                        </td>
                                    </tr>
                                </table>
                            @endforeach

                            <p style="margin:18px 0 0;font-size:12.5px;color:#6b6b70;">Show each ticket's QR code at the door to check in.</p>
                        </td>
                    </tr>
                </table>
                <div style="max-width:560px;margin:12px auto 0;font-size:11px;color:#a1a1aa;">&copy; {{ date('Y') }} DropRSVP</div>
            </td>
        </tr>
    </table>
</body>
</html>
