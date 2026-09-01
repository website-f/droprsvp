@extends('emails.layout', ['title' => 'Your payout has been paid'])

@section('preheader', "Your payout {$payout->reference} has been paid.")

@section('content')
    <h1 style="margin:0 0 14px;font-size:20px;font-weight:800;color:#18181b;">Your payout has been paid 🎉</h1>
    <p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#3f3f46;">Good news — your payout has been processed{{ $payout->method ? ' via '.$payout->method : '' }}.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8fa;border-radius:12px;margin:14px 0;">
        <tr><td style="padding:16px 18px;font-size:14px;line-height:1.7;color:#3f3f46;">
            <div>🔖&nbsp; Reference {{ $payout->reference }}</div>
            <div>💰&nbsp; Amount: {{ $payout->currency }} {{ number_format((float) $payout->amount, 2) }}</div>
        </td></tr>
    </table>

    @include('emails.partials.button', ['url' => url('/host/payouts'), 'text' => 'View payouts'])
@endsection
