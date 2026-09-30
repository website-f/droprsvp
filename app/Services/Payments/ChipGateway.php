<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Support\PaymentMethod;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * CHIP Collect payment gateway (https://docs.chip-in.asia).
 *
 * Creates a hosted "purchase" and returns its checkout_url; settlement is
 * confirmed via the success_callback webhook, whose X-Signature header is an
 * RSA-SHA256 signature over the raw body, verified with the account public key.
 * Test vs live is decided by the secret key, so there is one base URL.
 */
class ChipGateway implements PaymentGateway
{
    private const BASE = 'https://gate.chip-in.asia/api/v1';

    /** Per-request purchase cache, keyed by CHIP purchase id. @var array<string,?array> */
    private array $purchases = [];

    private function api(): PendingRequest
    {
        return Http::withToken((string) config('services.chip.secret'))
            ->acceptJson()
            ->baseUrl(self::BASE);
    }

    // ---- ticket orders --------------------------------------------------------

    public function createCheckout(Order $order): string
    {
        $res = $this->createRequest([
            'reference_number' => $order->reference,
            'amount' => (float) $order->total,
            'currency' => $order->currency,
            'name' => $order->buyer_name,
            'email' => $order->buyer_email,
            'description' => 'Tickets · '.($order->event?->title ?? 'DropRSVP'),
            // CHIP doesn't append our reference to the redirect, so we carry it ourselves.
            'redirect_url' => route('checkout.return', ['reference' => $order->reference]),
            'webhook' => route('webhooks.chip'),
        ]);

        $order->update(['payment_ref' => $res['id'] ?? null]);

        return $res['url'];
    }

    /**
     * Generic hosted-purchase creation (reused by ticket orders, boosts and
     * premium). Keeps the same abstract payload keys the old gateway used.
     *
     * @return array{id:?string,url:string}
     */
    public function createRequest(array $payload): array
    {
        $body = [
            'brand_id' => (string) config('services.chip.brand_id'),
            'reference' => $payload['reference_number'] ?? null,
            'success_redirect' => $payload['redirect_url'] ?? null,
            'failure_redirect' => $payload['redirect_url'] ?? null,
            'cancel_redirect' => $payload['redirect_url'] ?? null,
            'success_callback' => $payload['webhook'] ?? null,
            'client' => array_filter([
                'email' => $payload['email'] ?? null,
                'full_name' => $payload['name'] ?? null,
            ]),
            'purchase' => [
                'currency' => $payload['currency'] ?? config('services.chip.currency', 'MYR'),
                'products' => [[
                    'name' => Str::limit((string) ($payload['description'] ?? 'Payment'), 250, ''),
                    // CHIP expects the price in the smallest currency unit (cents).
                    'price' => (int) round(((float) ($payload['amount'] ?? 0)) * 100),
                    'quantity' => '1',
                ]],
            ],
        ];

        $res = $this->api()->post('/purchases/', $body)->throw()->json();

        return ['id' => $res['id'] ?? null, 'url' => $res['checkout_url'] ?? ''];
    }

    // ---- webhook / settlement -------------------------------------------------

    public function parseWebhook(Request $request): ?array
    {
        if (! $this->verifySignature($request)) {
            return null;
        }

        $data = $request->json()->all();

        return [
            'reference' => $data['reference'] ?? null,
            'paid' => ($data['status'] ?? null) === 'paid',
            'payment_ref' => $data['id'] ?? null,
            // CHIP posts the whole purchase object, so the instrument is right
            // here — no second API call needed on the webhook path.
            'payment' => PaymentMethod::fromChip($data),
        ];
    }

    /** True when the purchase for this order is settled at CHIP (return-page fallback). */
    public function isPaid(Order $order): bool
    {
        return $this->purchaseIsPaid($order->payment_ref);
    }

    /**
     * True when a hosted purchase (by CHIP purchase id) is settled. Generic across
     * orders, subscriptions and promotions — lets each return page reconcile
     * synchronously instead of waiting on the webhook.
     */
    public function purchaseIsPaid(?string $paymentRef): bool
    {
        return ($this->purchase($paymentRef)['status'] ?? null) === 'paid';
    }

    /**
     * How a settled purchase was paid: ['method' => ?string, 'brand' => ?string].
     *
     * Used on the return-page path, where — unlike the webhook — we do not have
     * the purchase body already. Callers that just reconciled with
     * purchaseIsPaid() will hit the per-request cache rather than the API.
     */
    public function paymentDetails(?string $paymentRef): array
    {
        return PaymentMethod::fromChip($this->purchase($paymentRef));
    }

    /**
     * Fetch a purchase, memoised for the request.
     *
     * The return page asks twice — once "is it paid?", once "how was it paid?" —
     * and a buyer coming back from the bank should not wait on two round trips
     * for one answer.
     *
     * @return array<string,mixed>|null
     */
    private function purchase(?string $paymentRef): ?array
    {
        if (! $paymentRef) {
            return null;
        }

        if (array_key_exists($paymentRef, $this->purchases)) {
            return $this->purchases[$paymentRef];
        }

        $res = $this->api()->get('/purchases/'.$paymentRef.'/');

        return $this->purchases[$paymentRef] = $res->successful() ? (array) $res->json() : null;
    }

    public function refund(Order $order, ?float $amount = null): bool
    {
        if (! $order->payment_ref) {
            return false;
        }

        return $this->api()->post('/purchases/'.$order->payment_ref.'/refund/', [
            'amount' => (int) round(($amount ?? (float) $order->total) * 100),
        ])->successful();
    }

    // ---- signature verification ----------------------------------------------

    /** RSA-SHA256 verify of the raw body against the X-Signature header. */
    private function verifySignature(Request $request): bool
    {
        $signature = base64_decode((string) $request->header('X-Signature', ''), true);
        $publicKey = $this->publicKey();

        if ($signature === false || $signature === '' || ! $publicKey) {
            return false;
        }

        return openssl_verify($request->getContent(), $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /** PEM public key — from config if provided, else fetched from CHIP + cached. */
    private function publicKey(): ?string
    {
        $configured = (string) config('services.chip.public_key');
        if ($configured !== '') {
            return $configured;
        }

        return Cache::remember('chip.public_key', now()->addDay(), function (): ?string {
            $res = $this->api()->get('/public_key/');
            if (! $res->successful()) {
                return null;
            }
            // The endpoint returns a JSON-encoded PEM string (surrounding quotes).
            $pem = $res->json();

            return is_string($pem) ? $pem : trim($res->body(), '"');
        });
    }
}
