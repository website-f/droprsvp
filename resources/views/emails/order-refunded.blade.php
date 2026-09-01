@extends('emails.layout', ['title' => 'Your order was refunded'])

@section('preheader', "Your order {$order->reference} has been refunded.")

@section('content')
    <h1 style="margin:0 0 14px;font-size:20px;font-weight:800;color:#18181b;">Your order was refunded</h1>
    <p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#3f3f46;">Hi {{ $order->buyer_name ?: 'there' }}, your order for <strong>{{ $event->title }}</strong> has been refunded and the tickets voided.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8fa;border-radius:12px;margin:14px 0;">
        <tr><td style="padding:16px 18px;font-size:14px;line-height:1.7;color:#3f3f46;">
            <div>🔖&nbsp; Order {{ $order->reference }}</div>
            <div>💳&nbsp; Refunded: RM {{ number_format((float) $order->total, 2) }}</div>
        </td></tr>
    </table>

    <p style="margin:8px 0 0;font-size:14px;color:#3f3f46;">Refunds typically take a few business days to appear, depending on your bank. If you have any questions, just reply to this email or contact us.</p>
@endsection
