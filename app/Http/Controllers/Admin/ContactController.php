<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;

class ContactController extends Controller
{
    /** Superadmin inbox of contact-form submissions. */
    public function index()
    {
        return inertia('admin/contact/index', [
            'messages' => ContactMessage::latest()->paginate(20)->through(fn (ContactMessage $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'phone' => $m->phone,
                'category' => $m->category,
                'message' => $m->message,
                'handled' => (bool) $m->handled_at,
                'at' => $m->created_at->format('j M Y · g:i A'),
            ]),
            'unhandled' => ContactMessage::whereNull('handled_at')->count(),
        ]);
    }

    /** Flip a message between handled / open. */
    public function toggle(ContactMessage $message)
    {
        $message->update(['handled_at' => $message->handled_at ? null : now()]);

        return back();
    }
}
