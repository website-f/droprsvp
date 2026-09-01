<?php

namespace App\Services\Payments;

use App\Models\Order;
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
        ];
    }

    /** True when the purchase for this order is settled at CHIP (return-page fallback). */
    public function isPaid(Order $order): bool
    {
        if (! $order->payment_ref) {
            return false;
        }

        $res = $this->api()->get('/purchases/'.$order->payment_ref.'/');

        return $res->successful() && ($res->json('status') === 'paid');
    }

    public function refund(Order $order): bool
    {
        if (! $order->payment_ref) {
            return false;
        }

        return $this->api()->post('/purchases/'.$order->payment_ref.'/refund/', [
            'amount' => (int) round((float) $order->total * 100),
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
