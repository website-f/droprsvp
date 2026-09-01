@extends('emails.layout', ['title' => 'New contact message'])

@section('preheader', "New {$msg->category} message from {$msg->name}.")

@section('content')
    <p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#6c63ff;">{{ ucfirst($msg->category) }} enquiry</p>
    <h1 style="margin:0 0 16px;font-size:20px;font-weight:800;color:#18181b;">New contact message</h1>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.8;color:#3f3f46;">
        <tr><td style="padding:2px 0;"><strong>Name:</strong> {{ $msg->name }}</td></tr>
        <tr><td style="padding:2px 0;"><strong>Email:</strong> <a href="mailto:{{ $msg->email }}">{{ $msg->email }}</a></td></tr>
        <tr><td style="padding:2px 0;"><strong>Phone:</strong> {{ $msg->phone ?: '—' }}</td></tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8fa;border-radius:12px;margin:16px 0;">
        <tr><td style="padding:16px 18px;font-size:14px;line-height:1.7;color:#3f3f46;white-space:pre-line;">{{ $msg->message }}</td></tr>
    </table>

    @include('emails.partials.button', ['url' => 'mailto:'.$msg->email, 'text' => 'Reply to '.$msg->name])
@endsection

@section('footnote', 'Sent from the DropRSVP contact form.')
