<?php

namespace App\Http\Controllers\Host\Edm;

use App\Http\Controllers\Controller;
use App\Models\EdmAccount;
use App\Models\EdmCreditEntry;
use App\Models\EdmCreditPurchase;
use App\Models\EdmSendingDomain;
use App\Models\EmailCampaign;
use App\Models\EmailConsent;
use App\Services\Edm\CreditPurchases;
use App\Services\Edm\Credits;
use App\Services\Edm\OrganizerGuard;
use App\Services\Edm\SendingDomains;
use App\Services\Payments\ChipGateway;
use App\Services\Payments\PaymentGateway;
use App\Support\Dates;
use App\Support\Edm\Consent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

/**
 * The organizer's Email marketing workspace around their campaigns: the
 * overview, credits (and buying them), and their own sending domains.
 */
class WorkspaceController extends Controller
{
    public function overview(Request $request)
    {
        $user = $request->user();
        $account = EdmAccount::where('organizer_id', $user->id)->first();
        $scope = Consent::scope($user->id);

        return Inertia::render('host/edm/overview', [
            'credits' => Credits::summary($user),
            'account' => ['suspended' => (bool) $account?->isSuspended(), 'reason' => $account?->suspended_reason],
            'rates' => OrganizerGuard::rates($user->id),
            'limits' => config('edm.organizers.guard'),
            'subscribers' => EmailConsent::where('scope', $scope)->where('status', 'subscribed')->count(),
            'joined30' => EmailConsent::where('scope', $scope)->where('status', 'subscribed')->where('consented_at', '>=', now()->subDays(30))->count(),
            'campaigns' => EmailCampaign::where('organizer_id', $user->id)->where('kind', '!=', 'automation')->latest('updated_at')->limit(5)->get()
                ->map(fn (EmailCampaign $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'status' => $c->status,
                    'recipients' => $c->recipients_count,
                    'sent' => $c->sent_count,
                    'open_rate' => $c->sent_count ? round(100 * $c->opened_count / $c->sent_count, 1) : null,
                    'click_rate' => $c->sent_count ? round(100 * $c->clicked_count / $c->sent_count, 1) : null,
                    'when' => Dates::display($c->started_at ?? $c->updated_at, 'j M, g:ia'),
                ]),
            'domain' => EdmSendingDomain::where('organizer_id', $user->id)->orderByRaw("CASE status WHEN 'verified' THEN 0 ELSE 1 END")->first(['domain', 'status']),
        ]);
    }

    // ---- credits ---------------------------------------------------------------

    public function credits(Request $request)
    {
        $user = $request->user();

        return Inertia::render('host/edm/credits', [
            'credits' => Credits::summary($user),
            'packs' => CreditPurchases::packs(),
            'premiumAllowance' => (int) config('edm.organizers.premium_allowance'),
            'ledger' => EdmCreditEntry::where('organizer_id', $user->id)
                ->with('campaign:id,name')
                ->latest('id')
                ->paginate(20)
                ->through(fn (EdmCreditEntry $e) => [
                    'id' => $e->id,
                    'pool' => $e->pool,
                    'delta' => $e->delta,
                    'reason' => $e->reason,
                    'note' => $e->note,
                    'campaign' => $e->campaign ? ['id' => $e->campaign->id, 'name' => $e->campaign->name] : null,
                    'when' => Dates::display($e->created_at, 'j M Y, g:ia'),
                ]),
            'purchases' => EdmCreditPurchase::where('organizer_id', $user->id)->latest('id')->limit(10)->get()
                ->map(fn (EdmCreditPurchase $p) => [
                    'reference' => $p->reference,
                    'credits' => $p->credits,
                    'amount' => (float) $p->amount,
                    'status' => $p->status,
                    'when' => Dates::display($p->paid_at ?? $p->created_at, 'j M Y'),
                ]),
        ]);
    }

    public function buy(Request $request, CreditPurchases $purchases, PaymentGateway $gateway)
    {
        $data = $request->validate(['pack' => ['required', 'string', 'max:20']]);

        try {
            $url = $purchases->start($request->user(), $data['pack'], $gateway);
        } catch (RuntimeException $e) {
            return back()->with('flash_error', $e->getMessage());
        }

        return $url
            ? Inertia::location($url)
            : redirect('/host/edm/credits')->with('flash_success', 'Credits added.');
    }

    /** CHIP sends the organizer back here; settle at once rather than wait for the webhook. */
    public function creditsReturn(Request $request, CreditPurchases $purchases, PaymentGateway $gateway): RedirectResponse
    {
        $purchase = EdmCreditPurchase::where('reference', (string) $request->query('reference'))
            ->where('organizer_id', $request->user()->id)
            ->first();

        if ($purchase && $purchase->status !== 'paid' && $gateway instanceof ChipGateway && $gateway->purchaseIsPaid($purchase->payment_ref)) {
            $purchases->settle($purchase, null, $gateway->paymentDetails($purchase->payment_ref));
        }

        return redirect('/host/edm/credits')->with(
            $purchase?->fresh()->status === 'paid' ? 'flash_success' : 'flash_warning',
            $purchase?->fresh()->status === 'paid'
                ? number_format($purchase->credits).' credits added. Thank you!'
                : 'Payment is still being confirmed. Your credits appear here as soon as it clears.',
        );
    }

    // ---- sending domains -------------------------------------------------------

    public function domains(Request $request)
    {
        return Inertia::render('host/edm/domains', [
            'domains' => EdmSendingDomain::where('organizer_id', $request->user()->id)->latest('id')->get()
                ->map(fn (EdmSendingDomain $d) => [
                    'id' => $d->id,
                    'domain' => $d->domain,
                    'status' => $d->status,
                    'checks' => $d->checks ?? [],
                    'records' => SendingDomains::records($d),
                    'checked' => Dates::display($d->last_checked_at, 'j M, g:ia'),
                    'verified' => Dates::display($d->verified_at, 'j M Y'),
                ]),
            'platformFrom' => config('edm.from.address'),
        ]);
    }

    public function addDomain(Request $request, SendingDomains $domains): RedirectResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:191']]);

        if (EdmSendingDomain::where('organizer_id', $request->user()->id)->count() >= 3) {
            return back()->with('flash_error', 'Up to three sending domains per account.');
        }

        try {
            $domains->add($request->user()->id, $data['domain']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['domain' => $e->getMessage()]);
        }

        return back()->with('flash_success', 'Domain added. Publish the DNS records below, then press Verify.');
    }

    public function verifyDomain(Request $request, EdmSendingDomain $domain, SendingDomains $domains): RedirectResponse
    {
        abort_unless((int) $domain->organizer_id === (int) $request->user()->id, 404);
        $domains->verify($domain);

        return back()->with($domain->isVerified() ? 'flash_success' : 'flash_warning', $domain->isVerified()
            ? "{$domain->domain} is verified. Choose it as the From address on a campaign."
            : 'Not verified yet. DNS changes can take up to an hour to appear — check the records and try again.');
    }

    public function removeDomain(Request $request, EdmSendingDomain $domain): RedirectResponse
    {
        abort_unless((int) $domain->organizer_id === (int) $request->user()->id, 404);

        // Unsent campaigns using it fall back to the platform address.
        EmailCampaign::where('sending_domain_id', $domain->id)->whereIn('status', ['draft', 'scheduled'])
            ->update(['sending_domain_id' => null, 'from_address' => null]);
        $domain->delete();

        return back()->with('flash_success', 'Domain removed.');
    }
}
