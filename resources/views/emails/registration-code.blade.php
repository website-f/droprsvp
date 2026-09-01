@extends('emails.layout', ['title' => 'Your verification code'])

@section('preheader', "Your {$code} verification code for DropRSVP.")

@section('content')
    <h1 style="margin:0 0 6px;font-size:20px;font-weight:800;color:#18181b;">Verify your email</h1>
    <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:#3f3f46;">Enter this code to finish creating your organizer account.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8fa;border-radius:12px;">
        <tr><td align="center" style="padding:22px;">
            <div style="font-size:38px;font-weight:800;letter-spacing:.32em;color:#18181b;">{{ $code }}</div>
        </td></tr>
    </table>

    <p style="margin:18px 0 0;font-size:13px;color:#8a8a92;">This code expires in 15 minutes. If you didn’t request it, you can safely ignore this email.</p>
@endsection
