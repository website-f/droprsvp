<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * HitPay payment gateway. Creates a payment request and verifies webhooks with the
 * HMAC salt. Sandbox vs live is switched by config('services.hitpay.mode').
 *
 * @see https://docs.hitpayapp.com/apis/
 */
class HitPayGateway implements PaymentGateway
{
    private function baseUrl(): string
    {
        return config('services.hitpay.mode') === 'live'
            ? 'https://api.hit-pay.com/v1'
            : 'https://api.sandbox.hit-pay.com/v1';
    }

    public function createCheckout(Order $order): string
    {
        $res = $this->createRequest([
            'amount' => number_format((float) $order->total, 2, '.', ''),
            'currency' => $order->currency,
            'reference_number' => $order->reference,
            'redirect_url' => route('checkout.return'),
            'webhook' => route('webhooks.hitpay'),
            'name' => $order->buyer_name,
            'email' => $order->buyer_email,
        ]);

        $order->update(['payment_ref' => $res['id'] ?? null]);

        return $res['url'];
    }

    /** Low-level payment-request call, reused for ticket orders + promotions. */
    public function createRequest(array $payload): array
    {
        return Http::withHeaders([
            'X-BUSINESS-API-KEY' => (string) config('services.hitpay.api_key'),
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json',
        ])->asForm()->post($this->baseUrl().'/payment-requests', $payload)->throw()->json();
    }

    public function parseWebhook(Request $request): ?array
    {
        $params = $request->all();
        $hmac = (string) ($params['hmac'] ?? '');
        unset($params['hmac']);

        // HitPay signs the alphabetically-sorted key+value concatenation with the salt.
        ksort($params);
        $payload = '';
        foreach ($params as $key => $value) {
            $payload .= $key.$value;
        }
        $computed = hash_hmac('sha256', $payload, (string) config('services.hitpay.salt'));

        if (! hash_equals($computed, $hmac)) {
            return null;
        }

        return [
            'reference' => $params['reference_number'] ?? null,
            'paid' => ($params['status'] ?? null) === 'completed',
        ];
    }

    public function refund(Order $order): bool
    {
        if (! $order->payment_ref) {
            return false;
        }

        $response = Http::withHeaders([
            'X-BUSINESS-API-KEY' => (string) config('services.hitpay.api_key'),
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json',
        ])->asForm()->post($this->baseUrl().'/refund', [
            'payment_id' => $order->payment_ref,
            'amount' => number_format((float) $order->total, 2, '.', ''),
        ]);

        return $response->successful();
    }
}

