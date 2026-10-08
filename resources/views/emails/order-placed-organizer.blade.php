@extends('emails.layout', ['title' => 'New booking'])

@php
    $event = $order->event;
    // What the organizer keeps: the buyer pays the ticket price, our
    // commission comes out of the takings, and the payout settles the rest.
    $earnings = max(0, (float) $order->total - (float) $order->fees);
@endphp

@section('preheader', "{$order->buyer_name} booked {$tickets} ticket(s) for {$event?->title}.")

@section('content')
    <p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#2ec4b6;">New booking</p>
    <h1 style="margin:0 0 16px;font-size:20px;font-weight:800;color:#18181b;">{{ $event?->title ?: 'Your event' }}</h1>

    <p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#3f3f46;">
        {{ $order->buyer_name ?: 'Someone' }} just booked
        {{ $tickets === 1 ? 'a ticket' : $tickets.' tickets' }}@if ($event?->starts_at) for {{ $event->starts_at->setTimezone($event->timezone)->format('D, j M Y') }}@endif.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.8;color:#3f3f46;">
        <tr><td style="padding:2px 0;"><strong>Reference:</strong> {{ $order->reference }}</td></tr>
        <tr><td style="padding:2px 0;"><strong>Name:</strong> {{ $order->buyer_name ?: '—' }}</td></tr>
        <tr><td style="padding:2px 0;"><strong>Email:</strong> <a href="mailto:{{ $order->buyer_email }}">{{ $order->buyer_email }}</a></td></tr>
        <tr><td style="padding:2px 0;"><strong>Phone:</strong> {{ $order->buyer_phone ?: '—' }}</td></tr>
        <tr><td style="padding:2px 0;"><strong>Booked at:</strong> {{ $order->paid_at?->format('d M Y, g:i A') ?: '—' }}</td></tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8fa;border-radius:12px;margin:16px 0;">
        <tr><td style="padding:16px 18px;font-size:14px;line-height:1.8;color:#3f3f46;">
            @foreach ($order->items as $item)
                <div>{{ $item->quantity }} × {{ $item->name }} — {{ $order->currency }} {{ number_format((float) $item->line_total, 2) }}</div>
            @endforeach

            {{-- The rule only separates; with nothing above it, it is just a stray line. --}}
            <div @if ($order->items->isNotEmpty()) style="margin-top:10px;padding-top:10px;border-top:1px solid #e4e4e7;" @endif>
                <div>They paid: {{ $order->currency }} {{ number_format((float) $order->total, 2) }}</div>
                @if ((float) $order->fees > 0)
                    <div style="color:#71717a;">Platform fee: − {{ $order->currency }} {{ number_format((float) $order->fees, 2) }}</div>
                @endif
                <div style="margin-top:4px;"><strong>You earn: {{ $order->currency }} {{ number_format($earnings, 2) }}</strong></div>
            </div>
        </td></tr>
    </table>

    @include('emails.partials.button', [
        'url' => $event ? url('/host/events/'.$event->slug.'/orders') : url('/host/events'),
        'text' => 'See the booking',
    ])
@endsection

@section('footnote')
    Sent because someone booked your event. Turn these off under
    <a href="{{ url('/settings/notifications') }}" style="color:#71717a;">Settings → Notifications</a>.
@endsection
