@extends('emails.layout', ['title' => $approved ? 'You’re approved to host' : 'An update on your application'])

@section('preheader', $approved ? 'Your organizer application was approved.' : 'An update on your organizer application.')

@section('content')
    <h1 style="margin:0 0 14px;font-size:20px;font-weight:800;color:#18181b;">Hi {{ $name }},</h1>

    @if ($approved)
        <p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#3f3f46;">Great news — your application to host events on <strong>{{ config('app.name') }}</strong> has been <strong style="color:#2ec4b6;">approved</strong>. You can now create events, sell tickets and manage everything from your dashboard.</p>
        @include('emails.partials.button', ['url' => url('/host/events'), 'text' => 'Go to your dashboard'])
    @else
        <p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#3f3f46;">Thanks for applying to host on <strong>{{ config('app.name') }}</strong>. After review, we’re not able to approve your application right now.</p>
        @if ($reason)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff4f4;border:1px solid #f6dada;border-radius:12px;margin:14px 0;">
                <tr><td style="padding:14px 16px;font-size:14px;color:#b23b3b;"><strong>Reason:</strong> {{ $reason }}</td></tr>
            </table>
        @endif
        <p style="margin:8px 0 0;font-size:15px;line-height:1.6;color:#3f3f46;">You’re welcome to update your details and re-apply anytime.</p>
        @include('emails.partials.button', ['url' => url('/host/apply'), 'text' => 'Update & re-apply'])
    @endif

    <p style="margin:8px 0 0;font-size:14px;color:#3f3f46;">Thanks,<br>The {{ config('app.name') }} team</p>
@endsection
