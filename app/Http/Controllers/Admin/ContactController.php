<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    /** Superadmin inbox of contact-form submissions. */
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['open', 'handled'], true) ? $request->query('status') : 'all';

        $messages = ContactMessage::latest()
            ->when($status === 'open', fn ($q) => $q->whereNull('handled_at'))
            ->when($status === 'handled', fn ($q) => $q->whereNotNull('handled_at'))
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ContactMessage $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'phone' => $m->phone,
                'category' => $m->category,
                'message' => $m->message,
                'handled' => (bool) $m->handled_at,
                'at' => $m->created_at->format('j M Y · g:i A'),
            ]);

        return inertia('admin/contact/index', [
            'messages' => $messages,
            'unhandled' => ContactMessage::whereNull('handled_at')->count(),
            'filters' => ['status' => $status],
        ]);
    }

    /** Flip a message between handled / open. */
    public function toggle(ContactMessage $message)
    {
        $message->update(['handled_at' => $message->handled_at ? null : now()]);

        return back();
    }

    /** Download the (filtered) inbox as a CSV — opens directly in Excel. */
    public function export(Request $request): StreamedResponse
    {
        $status = in_array($request->query('status'), ['open', 'handled'], true) ? $request->query('status') : 'all';

        $filename = 'contact-messages-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($status) {
            $out = fopen('php://output', 'w');
            // BOM so Excel reads UTF-8 (names/messages with accents) correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Received', 'Name', 'Email', 'Phone', 'Category', 'Status', 'Message']);

            ContactMessage::latest()
                ->when($status === 'open', fn ($q) => $q->whereNull('handled_at'))
                ->when($status === 'handled', fn ($q) => $q->whereNotNull('handled_at'))
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $m) {
                        fputcsv($out, [
                            $m->created_at->format('Y-m-d H:i'),
                            $m->name,
                            $m->email,
                            $m->phone,
                            $m->category,
                            $m->handled_at ? 'Handled' : 'Open',
                            $m->message,
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
