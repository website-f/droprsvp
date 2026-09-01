@extends('emails.layout', ['title' => 'Application received'])

@section('preheader', 'Thanks — we’ve received your vendor application and will review it shortly.')

@section('content')
    <h1 style="margin:0 0 14px;font-size:22px;font-weight:800;color:#18181b;">Application received 🎉</h1>
    <p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#3f3f46;">Hi {{ $name ?: 'there' }}, thanks for applying to host on {{ config('app.name') }}@if ($business) as <strong>{{ $business }}</strong>@endif.</p>
    <p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#3f3f46;">Our team will review your details and get back to you by email. Most applications are reviewed within a couple of business days — you’ll get another email the moment there’s a decision.</p>

    @include('emails.partials.button', ['url' => url('/host/pending'), 'text' => 'View application status'])
@endsection

@section('footnote', 'You’re receiving this because you applied to become a vendor on DropRSVP.')
