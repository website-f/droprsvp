<?php

namespace App\Support;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Send transactional mail AFTER the HTTP response (Laravel's defer) so slow SMTP
 * never delays the user's request, without needing a queue worker on cPanel.
 * Delivery failures are reported and never fatal.
 */
class Mailer
{
    public static function defer(string $to, Mailable $mailable): void
    {
        if (! $to) {
            return;
        }

        defer(function () use ($to, $mailable) {
            try {
                Mail::to($to)->send($mailable);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
