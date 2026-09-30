<?php

namespace App\Support;

use App\Mail\PlatformAlertMail;
use App\Models\AppNotification;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Tell the platform something happened.
 *
 * Sign-ups, applications and sales all emailed the person who did them and told
 * nobody on our side, so the first anyone here knew of a new organizer was
 * noticing the row in the admin panel. This raises both halves of the alert from
 * one call:
 *
 *   * an in-app notification for every superadmin (the bell inbox), and
 *   * an email to the support inbox — the `support_email` setting, falling back
 *     to the from-address, which is the same address the contact form uses so
 *     there is one place to configure where platform mail lands.
 *
 * Nothing here may throw. These are notifications about an action that has
 * already succeeded; a mail outage must never roll back somebody's sign-up.
 */
class PlatformAlert
{
    /**
     * @param  string  $type  groups it in the bell inbox: user, organizer, event…
     * @param  string  $url  admin-relative link to the thing it is about
     * @param  array<string,string>  $details  label => value rows for the email
     */
    public static function raise(
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        array $details = [],
        string $level = 'info',
    ): void {
        self::notifyAdmins($type, $title, $body, $url, $level);
        self::emailSupport($title, $body, $url, $details);
    }

    /** Where platform mail goes. Null when neither is configured. */
    public static function inbox(): ?string
    {
        return Setting::get('support_email') ?: config('mail.from.address') ?: null;
    }

    private static function notifyAdmins(string $type, string $title, ?string $body, ?string $url, string $level): void
    {
        try {
            AppNotification::notifyMany(
                User::role('superadmin')->pluck('id'),
                ['type' => $type, 'title' => $title, 'body' => $body, 'url' => $url, 'level' => $level],
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param  array<string,string>  $details */
    private static function emailSupport(string $title, ?string $body, ?string $url, array $details): void
    {
        $inbox = self::inbox();

        if (! $inbox) {
            return;
        }

        // Deferred so a slow SMTP server never sits in front of the response the
        // person who signed up is waiting for.
        defer(function () use ($inbox, $title, $body, $url, $details) {
            try {
                Mail::to($inbox)->send(new PlatformAlertMail($title, $body, $url ? url($url) : null, $details));
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
