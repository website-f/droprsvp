<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessageMail;
use App\Models\ContactMessage;
use App\Models\Setting;
use App\Support\SeoManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public const CATEGORIES = [
        ['value' => 'support', 'label' => 'Support'],
        ['value' => 'sales', 'label' => 'Sales'],
        ['value' => 'enquiry', 'label' => 'General enquiry'],
    ];

    /** Public contact page (server-rendered SEO). */
    public function show()
    {
        app(SeoManager::class)
            ->title('Contact us')
            ->description('Get in touch with the DropRSVP team — support, sales or a general enquiry.')
            ->canonical(url('/contact'));

        return inertia('public/contact', [
            'categories' => self::CATEGORIES,
            'seo' => ['title' => 'Contact us'],
        ]);
    }

    public function store(Request $request)
    {
        // Honeypot: a hidden field genuine users never see/fill. If a bot filled
        // it, feign success without storing or emailing anything.
        if (filled($request->input('website'))) {
            return back(303)->with('success', "Thanks — your message has been sent. We'll be in touch soon.");
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['required', 'string', 'max:40'],
            'category' => ['required', 'in:support,sales,enquiry'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $message = ContactMessage::create($data);

        // Notify the support inbox. Non-fatal: the message is always stored, so a
        // missing/misconfigured mailer never blocks the visitor. Set support_email
        // (Admin → Settings) + real MAIL_* creds to deliver for real.
        $to = Setting::get('support_email') ?: config('mail.from.address');
        if ($to) {
            \App\Support\Mailer::defer($to, new ContactMessageMail($message));
        }

        return back(303)->with('success', "Thanks — your message has been sent. We'll be in touch soon.");
    }
}
