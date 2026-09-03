@extends('emails.layout', ['title' => 'A ticket was sent to you'])

@section('preheader', "You've received a ticket for {$event->title}.")

@section('content')
    <p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#6c63ff;">A ticket is yours</p>
    <h1 style="margin:0 0 16px;font-size:22px;font-weight:800;line-height:1.25;color:#18181b;">{{ $event->title }}</h1>

    <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:#3f3f46;">Hi {{ $ticket->attendee_name ?: 'there' }}, @if ($fromName){{ $fromName }} has transferred @else you've been sent @endif a ticket to this event. It's now in your name — show its QR code at the door to check in.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8fa;border-radius:12px;margin:0 0 6px;">
        <tr><td style="padding:16px 18px;font-size:14px;line-height:1.7;color:#3f3f46;">
            @if ($event->starts_at)
                <div>🗓&nbsp; {{ $event->starts_at->copy()->setTimezone($event->timezone)->format('D, j M Y · g:i A') }}</div>
            @endif
            <div>📍&nbsp; {{ $event->is_online ? 'Online event' : ($event->venue_name ?: 'Venue to be confirmed') }}</div>
            @if (optional($ticket->ticketType)->name)
                <div>🎟&nbsp; {{ $ticket->ticketType->name }}@if ($ticket->seat_label) · {{ $ticket->seat_label }}@endif</div>
            @endif
        </td></tr>
    </table>

    @include('emails.partials.button', ['url' => $url, 'text' => 'View your ticket', 'color' => '#18181b'])

    <p style="margin:18px 0 0;font-size:13px;color:#8a8a92;">This link is your ticket — keep it private. If you weren’t expecting this, you can safely ignore this email.</p>
@endsection
