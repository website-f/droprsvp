@extends('emails.layout', ['title' => 'Your tickets'])

@section('preheader', "You're going to {$event->title} — your tickets are attached.")

@section('content')
    <p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#6c63ff;">You’re going!</p>
    <h1 style="margin:0 0 16px;font-size:22px;font-weight:800;line-height:1.25;color:#18181b;">{{ $event->title }}</h1>

    <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:#3f3f46;">Hi {{ $order->buyer_name ?: 'there' }}, your tickets are confirmed. Here are the details.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8fa;border-radius:12px;">
        <tr><td style="padding:16px 18px;font-size:14px;line-height:1.7;color:#3f3f46;">
            @if ($event->starts_at)
                <div>🗓&nbsp; {{ $event->starts_at->copy()->setTimezone($event->timezone)->format('D, j M Y · g:i A') }}</div>
            @endif
            <div>📍&nbsp; {{ $event->is_online ? 'Online event' : ($event->venue_name ?: 'Venue to be confirmed') }}</div>
            <div>🔖&nbsp; Order {{ $order->reference }} · Total RM {{ number_format((float) $order->total, 2) }}</div>
        </td></tr>
    </table>

    <p style="margin:22px 0 10px;font-size:12px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:#8a8a92;">Your {{ $tickets->count() }} ticket{{ $tickets->count() === 1 ? '' : 's' }}</p>

    @foreach ($tickets as $ticket)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e7e7ea;border-radius:12px;margin-bottom:10px;">
            <tr>
                <td style="padding:14px 16px;">
                    <div style="font-weight:600;font-size:14px;">{{ $ticket->attendee_name ?: 'Guest' }}</div>
                    <div style="font-size:12px;color:#8a8a92;">{{ optional($ticket->ticketType)->name }}@if ($ticket->seat_label) · {{ $ticket->seat_label }}@endif</div>
                </td>
                <td align="right" style="padding:14px 16px;">
                    <a href="{{ route('tickets.show', $ticket) }}" style="display:inline-block;background:#18181b;color:#ffffff;text-decoration:none;font-size:13px;font-weight:600;padding:10px 18px;border-radius:999px;">View ticket</a>
                </td>
            </tr>
        </table>
    @endforeach

    <p style="margin:18px 0 0;font-size:13px;color:#8a8a92;">Show each ticket’s QR code at the door to check in. Keep this email handy.</p>
@endsection

@section('footnote', 'A copy of your tickets is always available in your account under “My tickets”.')
