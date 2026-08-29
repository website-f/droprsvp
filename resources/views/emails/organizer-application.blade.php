<x-mail::message>
# Hi {{ $name }},

@if ($approved)
Great news — your application to host events on **{{ config('app.name') }}** has been **approved**! You can now create events, sell tickets and manage everything from your dashboard.

<x-mail::button :url="url('/host/events')">
Go to your dashboard
</x-mail::button>
@else
Thanks for applying to host on **{{ config('app.name') }}**. After review, we’re not able to approve your application right now.

@if ($reason)
**Reason:** {{ $reason }}
@endif

You’re welcome to update your details and re-apply anytime.

<x-mail::button :url="url('/host/apply')">
Update &amp; re-apply
</x-mail::button>
@endif

Thanks,<br>
The {{ config('app.name') }} team
</x-mail::message>
