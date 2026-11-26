<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Support\CountryRates;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Airwallex — card (and local) payments for events outside Malaysia.
 *
 * Same shape as CHIP: the server creates a hosted Checkout Session and sends
 * the buyer to its `url`; Airwallex sends them back to our return page, and
 * the webhook (payment_intent.succeeded / checkout_session.completed) is the
 * source of truth. The return and confirmation pages also ask the API
 * directly, because the redirect usually beats the webhook.
 *
 * Amounts are in major units (12.50), as Airwallex expects. Credentials come
 * from Settings → Airwallex (see Airwallex), never .env.
 *
 * Docs: https://www.airwallex.com/docs/payments/integration-options/web-checkout/checkout-session/quickstart
 */
class AirwallexGateway implements ConfirmsPayments, PaymentGateway
{
    /** Currencies without minor units (Airwallex rejects decimals for them). */
    private const ZERO_DECIMAL = ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'UGX', 'XAF', 'XOF', 'PYG', 'RWF', 'KMF', 'GNF', 'DJF', 'VUV', 'XPF', 'BIF'];

    /** Per-request cache of fetched objects, keyed by id. @var array<string, ?array> */
    private array $fetched = [];

    // ---- API plumbing ----------------------------------------------------------

    /** A bearer token, reused until shortly before it expires (they last 30 minutes). */
    public function token(bool $fresh = false): string
    {
        $c = Airwallex::config();
        $key = 'airwallex.token.'.$c['env'].'.'.md5($c['client_id'].$c['api_key']);

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(25), function () use ($c) {
            $res = Http::baseUrl(Airwallex::baseUrl())
                ->acceptJson()
                ->withHeaders(['x-client-id' => $c['client_id'], 'x-api-key' => $c['api_key']])
                ->post('/api/v1/authentication/login');

            if (! $res->successful() || ! $res->json('token')) {
                throw new RuntimeException('Airwallex sign-in failed ('.$res->status().'): '.Str::limit((string) ($res->json('message') ?? $res->body()), 200));
            }

            return (string) $res->json('token');
        });
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl(Airwallex::baseUrl())
            ->acceptJson()
            ->timeout(20)
            ->withToken($this->token())
            ->withHeaders(['x-api-version' => Airwallex::config()['api_version']]);
    }

    /** Check the saved credentials work. @return array{ok: bool, message: string} */
    public function test(): array
    {
        try {
            $this->token(fresh: true);

            return ['ok' => true, 'message' => 'Connected to Airwallex ('.Airwallex::config()['env'].').'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** An amount the way Airwallex wants it for this currency. */
    public static function amount(float $amount, string $currency): float
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? round($amount) : round($amount, 2);
    }

    // ---- ticket orders -----------------------------------------------------------

    public function createCheckout(Order $order): string
    {
        $order->loadMissing('event');
        $c = Airwallex::config();
        $currency = strtoupper((string) $order->currency);
        $country = CountryRates::countryOf($order->event?->region);

        $res = $this->api()->post('/api/v1/checkout/checkout_sessions/create', array_filter([
            'request_id' => (string) Str::uuid(),
            'account_id' => $c['account_id'],
            'mode' => 'PAYMENT',
            'currency' => $currency,
            'line_items' => [[
                'quantity' => 1,
                'price' => [
                    'currency' => $currency,
                    'unit_amount' => self::amount((float) $order->total, $currency),
                    'product' => ['name' => Str::limit('Tickets · '.($order->event?->title ?? config('app.name')), 120, '')],
                ],
            ]],
            // Back to our return page either way; it asks the API what happened.
            'success_url' => route('checkout.return', ['reference' => $order->reference]),
            'back_url' => route('checkout.show', $order),
            'customer_data' => array_filter([
                'email' => $order->buyer_email,
                'name' => $order->buyer_name,
                'country_code' => $country,
            ]),
            'payment_intent_data' => [
                // Our order reference: it comes back on the payment intent and
                // its webhooks, which is how a payment finds its order.
                'merchant_order_id' => $order->reference,
            ],
            'metadata' => ['reference' => $order->reference],
        ], fn ($v) => $v !== null && $v !== []));

        if (! $res->successful() || ! $res->json('url')) {
            throw new RuntimeException('Airwallex checkout failed ('.$res->status().'): '.Str::limit((string) ($res->json('message') ?? $res->body()), 300));
        }

        $order->update(['payment_ref' => (string) $res->json('id')]);

        return (string) $res->json('url');
    }

    // ---- settlement ----------------------------------------------------------------

    /** The settled payment intent behind an order, or null while it is not paid. */
    private function succeededIntent(Order $order): ?array
    {
        $intentId = $this->intentId($order->payment_ref);
        $intent = $intentId ? $this->fetch('/api/v1/pa/payment_intents/'.$intentId) : null;

        return ($intent['status'] ?? null) === 'SUCCEEDED' ? $intent : null;
    }

    /** The payment intent id for a stored reference (a session id or an intent id). */
    private function intentId(?string $ref): ?string
    {
        if (! $ref) {
            return null;
        }
        if (str_starts_with($ref, 'int_')) {
            return $ref;
        }

        return $this->fetch('/api/v1/checkout/checkout_sessions/'.$ref)['payment_intent_id'] ?? null;
    }

    public function isPaid(Order $order): bool
    {
        return $this->succeededIntent($order) !== null;
    }

    public function settlement(Order $order): array
    {
        $intent = $this->succeededIntent($order);

        return [
            // Store the intent id: refunds are made against it.
            'payment_ref' => $intent['id'] ?? $order->payment_ref,
            'payment' => self::paymentMethod($intent),
        ];
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $path): ?array
    {
        if (array_key_exists($path, $this->fetched)) {
            return $this->fetched[$path];
        }

        try {
            $res = $this->api()->get($path);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return $this->fetched[$path] = $res->successful() ? (array) $res->json() : null;
    }

    /** ['method' => 'card', 'brand' => 'visa'] from an intent's latest attempt. */
    public static function paymentMethod(?array $intent): array
    {
        $pm = (array) ($intent['latest_payment_attempt']['payment_method'] ?? []);
        $type = strtolower((string) ($pm['type'] ?? ''));

        if ($type === '') {
            return [];
        }

        $brand = strtolower(str_replace(' ', '', (string) ($pm['card']['brand'] ?? '')));
        $brand = ['americanexpress' => 'amex', 'master' => 'mastercard'][$brand] ?? $brand;

        return [
            'method' => in_array($type, ['card', 'applepay', 'googlepay'], true) ? 'card' : (in_array($type, ['paynow', 'promptpay', 'qris', 'duitnow'], true) ? 'duitnow_qr' : 'ewallet'),
            'brand' => $brand ?: $type,
        ];
    }

    // ---- webhooks -------------------------------------------------------------------

    /**
     * Verify + read a webhook. The signature is HMAC-SHA256 (hex) of the
     * x-timestamp header followed by the raw body, keyed with the endpoint's
     * secret (Airwallex → Developer → Webhooks).
     */
    public function parseWebhook(Request $request): ?array
    {
        $secret = Airwallex::config()['webhook_secret'];
        $timestamp = (string) $request->header('x-timestamp', '');
        $signature = (string) $request->header('x-signature', '');

        if ($secret === '' || $timestamp === '' || $signature === '') {
            return null;
        }

        if (! hash_equals(hash_hmac('sha256', $timestamp.$request->getContent(), $secret), $signature)) {
            return null;
        }

        // Replays: refuse anything signed more than a day ago (ms timestamps).
        if (abs(now()->getTimestampMs() - (int) $timestamp) > 86_400_000) {
            return null;
        }

        $name = (string) $request->json('name', '');
        $object = (array) $request->json('data.object', []);

        if ($name === 'payment_intent.succeeded') {
            return [
                'reference' => $object['merchant_order_id'] ?? null,
                'paid' => ($object['status'] ?? 'SUCCEEDED') === 'SUCCEEDED',
                'payment_ref' => $object['id'] ?? null,
                'payment' => self::paymentMethod($object),
            ];
        }

        // A completed session can still be an unsettled direct debit, so it
        // only counts once its intent has succeeded.
        if ($name === 'checkout_session.completed') {
            $intentId = $object['payment_intent_id'] ?? null;
            $intent = $intentId ? $this->fetch('/api/v1/pa/payment_intents/'.$intentId) : null;

            return [
                'reference' => $object['metadata']['reference'] ?? ($intent['merchant_order_id'] ?? null),
                'paid' => ($intent['status'] ?? null) === 'SUCCEEDED',
                'payment_ref' => $intentId,
                'payment' => self::paymentMethod($intent),
            ];
        }

        // Anything else (refund.*, payment_failed…): acknowledged, no action.
        return ['reference' => null, 'paid' => false, 'payment_ref' => null];
    }

    // ---- refunds --------------------------------------------------------------------

    public function refund(Order $order, ?float $amount = null): bool
    {
        $intentId = $this->intentId($order->payment_ref);

        if (! $intentId) {
            return false;
        }

        $currency = strtoupper((string) $order->currency);

        try {
            $res = $this->api()->post('/api/v1/pa/refunds/create', [
                'request_id' => (string) Str::uuid(),
                'payment_intent_id' => $intentId,
                'amount' => self::amount($amount ?? (float) $order->total, $currency),
                'reason' => 'Refund for order '.$order->reference,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return $res->successful() && in_array($res->json('status'), ['RECEIVED', 'ACCEPTED', 'SETTLED'], true);
    }
}
