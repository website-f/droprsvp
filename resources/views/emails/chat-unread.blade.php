@extends('emails.layout', ['title' => 'You have unread messages'])

@section('preheader', $total === 1 ? 'You have 1 unread message on DropRSVP.' : "You have {$total} unread messages on DropRSVP.")

@section('content')
    <p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#6c63ff;">Messages</p>
    <h1 style="margin:0 0 16px;font-size:20px;font-weight:800;color:#18181b;">Hi {{ strtok(trim($recipientName), ' ') ?: 'there' }}, you have {{ $total === 1 ? 'a new message' : $total.' new messages' }}</h1>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8fa;border-radius:12px;margin:0 0 20px;">
        @foreach ($threads as $t)
            <tr><td style="padding:14px 18px;border-bottom:1px solid #ececf1;font-size:14px;line-height:1.5;color:#3f3f46;">
                <div style="font-weight:700;color:#18181b;">{{ $t['name'] }} @if ($t['count'] > 1)<span style="font-weight:500;color:#71717a;">· {{ $t['count'] }} messages</span>@endif</div>
                <div style="color:#52525b;">{{ $t['preview'] }}</div>
            </td></tr>
        @endforeach
    </table>

    @include('emails.partials.button', ['url' => url('/messages'), 'text' => 'Open messages'])
@endsection

@section('footnote', 'You get this when messages wait more than a few minutes while you are away. Turn it off in Settings → Notifications.')
