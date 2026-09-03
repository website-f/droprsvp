@extends('emails.layout', ['title' => 'A spot just opened'])

@section('preheader', "A ticket is available for {$event->title} — grab it before it's gone.")

@section('content')
    <p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#6c63ff;">You're off the waitlist</p>
    <h1 style="margin:0 0 16px;font-size:22px;font-weight:800;line-height:1.25;color:#18181b;">{{ $event->title }}</h1>

    <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:#3f3f46;">Hi {{ $name ?: 'there' }}, good news — a spot has opened up for this event. Tickets are limited, so grab yours before they’re gone.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8fa;border-radius:12px;margin:0 0 6px;">
        <tr><td style="padding:16px 18px;font-size:14px;line-height:1.7;color:#3f3f46;">
            @if ($event->starts_at)
                <div>🗓&nbsp; {{ $event->starts_at->copy()->setTimezone($event->timezone)->format('D, j M Y · g:i A') }}</div>
            @endif
            <div>📍&nbsp; {{ $event->is_online ? 'Online event' : ($event->venue_name ?: 'Venue to be confirmed') }}</div>
        </td></tr>
    </table>

    @include('emails.partials.button', ['url' => $url, 'text' => 'Get your ticket', 'color' => '#18181b'])

    <p style="margin:18px 0 0;font-size:13px;color:#8a8a92;">Spots are first come, first served — this invite doesn’t hold a ticket for you.</p>
@endsection
