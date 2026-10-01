<?php

namespace App\Http\Controllers;

use App\Models\EmailLink;
use App\Models\EmailSend;
use App\Support\Edm\Consent;
use App\Support\Edm\Personalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The links inside a campaign email: open pixel, tracked clicks, "view in
 * browser" and unsubscribe.
 *
 * Every one identifies the recipient by the send's random token only. They are
 * public (the reader is not signed in, and may be reading on another device),
 * so each responds with nothing useful to anyone who guesses wrong, and every
 * page is noindex — these URLs travel in forwarded mail and must never be
 * crawled.
 */
class EdmTrackingController extends Controller
{
    /** A transparent 1x1 GIF. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(string $token): Response
    {
        if ($send = $this->send($token)) {
            $this->recordOpen($send);
        }

        // Always the pixel, even for an unknown token, so a guess learns nothing.
        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function click(string $token, int $link): RedirectResponse
    {
        $send = $this->send($token);

        if (! $send) {
            return redirect('/');
        }

        // The link must belong to THIS send's campaign. Otherwise the route is
        // an open redirect: any token plus any link id would bounce a reader to
        // whatever URL some other campaign registered.
        $target = EmailLink::where('id', $link)->where('campaign_id', $send->campaign_id)->first();

        if (! $target) {
            return redirect('/');
        }

        DB::transaction(function () use ($send, $target) {
            $first = $send->clicked_at === null;

            $send->forceFill([
                'clicked_at' => $send->clicked_at ?? now(),
                'click_count' => $send->click_count + 1,
            ])->save();

            $target->increment('clicks');

            if ($first) {
                $target->increment('unique_clicks');
                $send->campaign()->increment('clicked_count');
            }
        });

        // A click proves the email was opened even when images were blocked,
        // which they are by default in many clients.
        $this->recordOpen($send->fresh());

        return redirect()->away($target->url);
    }

    public function view(string $token): Response
    {
        $send = $this->send($token);

        abort_unless($send && $send->campaign?->html, 404);

        return response(Personalizer::forBrowser($send->campaign, $send), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * The unsubscribe page.
     *
     * GET only SHOWS a confirmation. It does not unsubscribe: corporate mail
     * scanners and link previewers fetch every URL in an email, and a GET that
     * acted would unsubscribe people who never clicked anything.
     */
    public function confirm(string $token): Response
    {
        $send = $this->send($token);

        return response()->view('edm.unsubscribe', [
            'token' => $token,
            'valid' => (bool) $send,
            'done' => $send && $send->unsubscribed_at !== null,
            'sender' => $send?->campaign?->from_name ?: config('edm.from.name', 'DropRSVP'),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Unsubscribe. Serves both the button on the page above and the RFC 8058
     * one-click POST that Gmail and Yahoo send from their own servers when the
     * reader presses "Unsubscribe" beside the sender name — which is why this
     * route takes no CSRF token (see bootstrap/app.php).
     */
    public function unsubscribe(Request $request, string $token): Response
    {
        $send = $this->send($token);

        if ($send) {
            Consent::revoke($send->email, 'unsubscribe', $send->campaign?->organizer_id, $request->ip());

            if ($send->unsubscribed_at === null) {
                $send->forceFill(['unsubscribed_at' => now()])->save();
                $send->campaign()->increment('unsubscribed_count');
            }
        }

        // The one-click POST is machine-to-machine; it only needs a 2xx.
        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response('', 200);
        }

        return response()->view('edm.unsubscribe', [
            'token' => $token,
            'valid' => (bool) $send,
            'done' => (bool) $send,
            'sender' => $send?->campaign?->from_name ?: config('edm.from.name', 'DropRSVP'),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * The re-permission email's "Yes, keep me posted".
     *
     * GET only shows the button, for the same reason as unsubscribe: mail
     * scanners open every link, and a GET that recorded consent would sign up
     * people who never clicked — consent nobody gave is worth nothing.
     */
    public function confirmSubscribe(string $token): Response
    {
        $send = $this->permissionSend($token);

        return response()->view('edm.subscribe', [
            'token' => $token,
            'valid' => (bool) $send,
            'done' => $send && Consent::isSubscribed($send->email),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function subscribe(Request $request, string $token): Response
    {
        $send = $this->permissionSend($token);

        // Only from a re-permission email, and only for the person it was sent
        // to: the token is theirs. Someone who unsubscribed since still gets to
        // change their mind — this is them choosing, explicitly.
        if ($send && ! Consent::isSubscribed($send->email)) {
            Consent::grant($send->email, 'repermission', $send->user, null, $request->ip());
        }

        return response()->view('edm.subscribe', [
            'token' => $token,
            'valid' => (bool) $send,
            'done' => (bool) $send,
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function permissionSend(string $token): ?EmailSend
    {
        $send = $this->send($token);

        return $send && $send->campaign?->kind === 'repermission' ? $send : null;
    }

    private function send(string $token): ?EmailSend
    {
        // Tokens are exactly 40 characters; anything else is not worth a query.
        if (strlen($token) !== 40 || ! ctype_alnum($token)) {
            return null;
        }

        return EmailSend::with('campaign')->where('token', $token)->first();
    }

    private function recordOpen(EmailSend $send): void
    {
        DB::transaction(function () use ($send) {
            $first = $send->opened_at === null;

            $send->forceFill([
                'opened_at' => $send->opened_at ?? now(),
                'open_count' => $send->open_count + 1,
            ])->save();

            if ($first) {
                $send->campaign()->increment('opened_count');
            }
        });
    }
}
