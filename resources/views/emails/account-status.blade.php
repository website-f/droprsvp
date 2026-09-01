@extends('emails.layout', ['title' => $disabled ? 'Account disabled' : 'Account reactivated'])

@section('preheader', $disabled ? 'Your account has been disabled.' : 'Good news — your account is active again.')

@section('content')
    @if ($disabled)
        <h1 style="margin:0 0 14px;font-size:22px;font-weight:800;color:#18181b;">Your account has been disabled</h1>
        <p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#3f3f46;">Hi {{ $name ?: 'there' }}, your {{ config('app.name') }} account has been disabled by our team, so you won’t be able to sign in for now.</p>
        @if ($reason)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;margin:14px 0;">
                <tr><td style="padding:14px 16px;font-size:14px;line-height:1.6;color:#991b1b;"><strong>Reason:</strong> {{ $reason }}</td></tr>
            </table>
        @endif
        <p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#3f3f46;">If you think this was a mistake, reply to this email or contact <a href="mailto:support@droprsvp.com">support@droprsvp.com</a> and we’ll take a look.</p>
    @else
        <h1 style="margin:0 0 14px;font-size:22px;font-weight:800;color:#18181b;">Your account is active again ✅</h1>
        <p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#3f3f46;">Hi {{ $name ?: 'there' }}, good news — your {{ config('app.name') }} account has been reactivated. You can sign in again as usual.</p>
        @include('emails.partials.button', ['url' => url('/login'), 'text' => 'Sign in'])
    @endif
@endsection
