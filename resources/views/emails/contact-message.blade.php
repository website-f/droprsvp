<x-mail::message>
# New {{ $msg->category }} message

**Name:** {{ $msg->name }}
**Email:** {{ $msg->email }}
**Phone:** {{ $msg->phone ?: '—' }}
**Category:** {{ ucfirst($msg->category) }}

**Message:**

{{ $msg->message }}

<x-mail::button :url="'mailto:'.$msg->email">
Reply to {{ $msg->name }}
</x-mail::button>

Sent from the DropRSVP contact form.
</x-mail::message>
