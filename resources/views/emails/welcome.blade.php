@extends('emails.layout', ['title' => 'Welcome to DropRSVP'])

@section('preheader', 'Your DropRSVP account is ready — discover events near you.')

@section('content')
    <h1 style="margin:0 0 14px;font-size:22px;font-weight:800;color:#18181b;">Welcome, {{ $user->name }} 👋</h1>
    <p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#3f3f46;">Your {{ config('app.name') }} account is ready. Discover events happening near you, grab tickets in a tap, and keep them all in one place with QR check-in at the door.</p>

    @include('emails.partials.button', ['url' => url('/en-my/all'), 'text' => 'Browse events'])

    <p style="margin:6px 0 0;font-size:14px;line-height:1.6;color:#3f3f46;">Running your own events? You can <a href="{{ url('/get-started') }}">become a vendor</a> to sell tickets and manage guests.</p>
@endsection

@section('footnote', 'Glad to have you with us.')
