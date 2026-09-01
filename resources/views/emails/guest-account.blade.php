@extends('emails.layout', ['title' => 'Your account is ready'])

@section('preheader', 'We created an account so you can view and re-download your tickets.')

@section('content')
    <h1 style="margin:0 0 14px;font-size:22px;font-weight:800;color:#18181b;">Your account is ready 🎫</h1>
    <p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#3f3f46;">Hi {{ $name ?: 'there' }}, we created a {{ config('app.name') }} account for you so you can view your tickets any time, re-download them, and follow organizers for their next events.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8fa;border-radius:12px;margin:14px 0;">
        <tr><td style="padding:16px 18px;font-size:14px;line-height:1.9;color:#3f3f46;">
            <div>Email: <strong>{{ $email }}</strong></div>
            <div>Temporary password: <strong style="font-family:monospace;font-size:15px;letter-spacing:1px;">{{ $password }}</strong></div>
        </td></tr>
    </table>

    <p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#3f3f46;">Sign in with the temporary password above — you’ll be asked to set your own password straight away.</p>

    @include('emails.partials.button', ['url' => url('/login'), 'text' => 'Sign in'])
@endsection

@section('footnote', 'For your security, please set a new password the first time you sign in.')
