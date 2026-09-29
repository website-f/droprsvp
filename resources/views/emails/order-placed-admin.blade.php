@extends('emails.layout', ['title' => 'New order'])

@php
    $event = $order->event;
    $tickets = (int) $order->items->sum('quantity');
@endphp

@section('preheader', "{$order->buyer_name} bought {$tickets} ticket(s) for {$event?->title}.")

@section('content')
    <p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#6c63ff;">Paid order</p>
    <h1 style="margin:0 0 16px;font-size:20px;font-weight:800;color:#18181b;">{{ $event?->title ?: 'New order' }}</h1>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.8;color:#3f3f46;">
        <tr><td style="padding:2px 0;"><strong>Reference:</strong> {{ $order->reference }}</td></tr>
        <tr><td style="padding:2px 0;"><strong>Buyer:</strong> {{ $order->buyer_name ?: '—' }}</td></tr>
        <tr><td style="padding:2px 0;"><strong>Email:</strong> <a href="mailto:{{ $order->buyer_email }}">{{ $order->buyer_email }}</a></td></tr>
        <tr><td style="padding:2px 0;"><strong>Phone:</strong> {{ $order->buyer_phone ?: '—' }}</td></tr>
        <tr><td style="padding:2px 0;"><strong>Tickets:</strong> {{ $tickets }}</td></tr>
        <tr><td style="padding:2px 0;"><strong>Organizer:</strong> {{ $event?->user?->name ?: '—' }}</td></tr>
        <tr><td style="padding:2px 0;"><strong>Paid at:</strong> {{ $order->paid_at?->format('d M Y, g:i A') ?: '—' }}</td></tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8fa;border-radius:12px;margin:16px 0;">
        <tr><td style="padding:16px 18px;font-size:14px;line-height:1.8;color:#3f3f46;">
            @foreach ($order->items as $item)
                <div>{{ $item->quantity }} × {{ $item->name }} — {{ $order->currency }} {{ number_format((float) $item->line_total, 2) }}</div>
            @endforeach

            <div style="margin-top:10px;padding-top:10px;border-top:1px solid #e4e4e7;">
                <div>Subtotal: {{ $order->currency }} {{ number_format((float) $order->subtotal, 2) }}</div>
                @if ((float) $order->discount > 0)
                    <div>Discount: − {{ $order->currency }} {{ number_format((float) $order->discount, 2) }}</div>
                @endif
                @if ((float) $order->tax > 0)
                    <div>Tax: {{ $order->currency }} {{ number_format((float) $order->tax, 2) }}</div>
                @endif
                <div><strong>Buyer paid: {{ $order->currency }} {{ number_format((float) $order->total, 2) }}</strong></div>
                {{-- The commission is ours, withheld from the organizer's payout —
                     it is not part of what the buyer was charged. --}}
                <div style="color:#71717a;">Platform fee (from organizer): {{ $order->currency }} {{ number_format((float) $order->fees, 2) }}</div>
            </div>
        </td></tr>
    </table>

    @include('emails.partials.button', ['url' => url('/admin/orders'), 'text' => 'Open in admin'])
@endsection

@section('footnote', 'Sent because an order was paid on DropRSVP.')
