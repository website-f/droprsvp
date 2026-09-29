@extends('emails.layout', ['title' => $heading])

@section('preheader', $body ?: $heading)

@section('content')
    <p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#6c63ff;">DropRSVP</p>
    <h1 style="margin:0 0 16px;font-size:20px;font-weight:800;color:#18181b;">{{ $heading }}</h1>

    @if ($body)
        <p style="margin:0 0 16px;font-size:14px;line-height:1.7;color:#3f3f46;">{{ $body }}</p>
    @endif

    @if ($details)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8fa;border-radius:12px;margin:0 0 16px;">
            <tr><td style="padding:16px 18px;font-size:14px;line-height:1.8;color:#3f3f46;">
                @foreach ($details as $label => $value)
                    <div><strong>{{ $label }}:</strong> {{ $value }}</div>
                @endforeach
            </td></tr>
        </table>
    @endif

    @if ($actionUrl)
        @include('emails.partials.button', ['url' => $actionUrl, 'text' => 'Open in admin'])
    @endif
@endsection

@section('footnote', 'Sent to the DropRSVP support inbox.')
