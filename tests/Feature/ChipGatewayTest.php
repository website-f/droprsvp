<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\ChipGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChipGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.chip.secret', 'sk_test_123');
        Config::set('services.chip.brand_id', 'brand-uuid-1');
        Config::set('services.chip.currency', 'MYR');
    }

    public function test_create_request_posts_a_chip_purchase_with_price_in_cents(): void
    {
        Http::fake(['gate.chip-in.asia/*' => Http::response(['id' => 'pur_1', 'checkout_url' => 'https://gate.chip-in.asia/p/pur_1/', 'status' => 'created'], 200)]);

        $res = (new ChipGateway())->createRequest([
            'reference_number' => 'DRSVP-ABC', 'amount' => 49.5, 'currency' => 'MYR',
            'email' => 'buyer@example.com', 'name' => 'Buyer One', 'description' => 'Tickets',
            'redirect_url' => 'https://app.test/return', 'webhook' => 'https://app.test/webhooks/chip',
        ]);

        $this->assertSame('pur_1', $res['id']);
        $this->assertSame('https://gate.chip-in.asia/p/pur_1/', $res['url']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/api/v1/purchases/')
                && $request->hasHeader('Authorization', 'Bearer sk_test_123')
                && $body['brand_id'] === 'brand-uuid-1'
                && $body['reference'] === 'DRSVP-ABC'
                && $body['success_callback'] === 'https://app.test/webhooks/chip'
                && $body['purchase']['products'][0]['price'] === 4950   // cents
                && $body['purchase']['currency'] === 'MYR';
        });
    }

    public function test_valid_webhook_signature_is_accepted_and_parsed(): void
    {
        [$priv, $pub] = $this->keypair();
        Config::set('services.chip.public_key', $pub);

        $body = json_encode(['id' => 'pur_9', 'reference' => 'DRSVP-XYZ', 'status' => 'paid']);
        openssl_sign($body, $sig, $priv, OPENSSL_ALGO_SHA256);

        $parsed = (new ChipGateway())->parseWebhook($this->signedRequest($body, base64_encode($sig)));

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed['paid']);
        $this->assertSame('DRSVP-XYZ', $parsed['reference']);
        $this->assertSame('pur_9', $parsed['payment_ref']);
    }

    public function test_tampered_webhook_body_is_rejected(): void
    {
        [$priv, $pub] = $this->keypair();
        Config::set('services.chip.public_key', $pub);

        $body = json_encode(['id' => 'pur_9', 'reference' => 'DRSVP-XYZ', 'status' => 'paid']);
        openssl_sign($body, $sig, $priv, OPENSSL_ALGO_SHA256);
        $tampered = json_encode(['id' => 'pur_9', 'reference' => 'DRSVP-XYZ', 'status' => 'paid', 'extra' => 'evil']);

        $this->assertNull((new ChipGateway())->parseWebhook($this->signedRequest($tampered, base64_encode($sig))));
    }

    public function test_missing_signature_is_rejected(): void
    {
        [, $pub] = $this->keypair();
        Config::set('services.chip.public_key', $pub);

        $this->assertNull((new ChipGateway())->parseWebhook($this->signedRequest('{"status":"paid"}', '')));
    }

    public function test_refund_calls_the_chip_refund_endpoint_in_cents(): void
    {
        Http::fake(['gate.chip-in.asia/*' => Http::response(['status' => 'refunded'], 200)]);
        $host = User::factory()->create();
        $event = Event::create(['user_id' => $host->id, 'title' => 'E', 'slug' => 'e-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        $order = Order::create(['reference' => 'DRSVP-R', 'event_id' => $event->id, 'status' => 'paid', 'total' => 30.00, 'currency' => 'MYR', 'payment_ref' => 'pur_5']);

        $this->assertTrue((new ChipGateway())->refund($order));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/purchases/pur_5/refund/') && $r->data()['amount'] === 3000);
    }

    // --- helpers ---------------------------------------------------------------

    /** A fixed RSA test keypair (openssl_pkey_new needs an openssl.cnf that isn't present on Windows CI). */
    private function keypair(): array
    {
        $priv = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCz+kfQ4U0v4tdc
9voclr9mWbfmFqmqR0/X3MwBj1hpb+2lb82+YHf7ii6LX9vU9ZoR3u9szZM/885v
xlMpSZlrlPRMUxoHKWtDEgBhyT5wRHiVswmr7opnCPwA6bYRnsKgzN0OvYnYoJvh
QVDMwjRSP//xluIdkKu51Kjdbmi92bYAgbcv+DqiRzC/ZzHg3eM2TZTWF+I6W+Yy
tdJLh09CL8e/xp6VcuukExh9PP/EcRV88r2JHyZmep0iLPm1av7tOjVdLDbpwoEH
h/7zfI6C/nlams/TbP002341XooOPEfEGft5PZ1DCjcLn3L02+8T4nGuzQ6g+16D
n0J6OYzFAgMBAAECggEADSTB7Uq7raIWBhRRVEvCb/6TjaQDdJiFoU2SJndp+c7Y
3tD8VQllg1yzJOGPKkCPcmIPpfUzajzrZNu2dtiI2EPGCloaF5wApdX6nVzD7xEv
cm5859RhWvOO6waMxQ4NLg7kzyISnyit8AFEBD59V+THOitbMwgkMMuLhrRJkoew
3+AgjqANxYserW/DBPQGW+RTM+EoU09ReYrfIGQ8+kQYY+0ES9HNLOAYu/ivRLFv
DPAqHHWumYbk+S1JuAn+jlpQiPSwwhyQKWVj/3gD9TlwCPZ5NnXAVRQOdtZAHyWI
kYZopIQdBHNSFqYnGGKYQGLc0+87GK7ewgZlFLAypwKBgQD77qsDGiLZR7eBdjcH
NtEr4d5vPgV1/oqXLr27OEoO+se8SUaUTrqfz9WoAzmBFUVSFsPyFXpkKf9QIfu5
Xf36dV/XFs7N7VgXUT3tz3jB5Hh5dalXEF1NFqjXoxiHHqiJ/nPBq2ZV8xOxi44O
N9fll7vZUfhSollvjmXvPWJZXwKBgQC24jJWdkhsRVZsqG66lY+yjA1VTidC9ASd
ic9p+99EwXOzwzDyKA4jxeFB2kaAtnCcab/pN3WBCfp2s6MCpDJg6ioM4idApSNG
aBuuqmn7VW6YZmEDFKozJt5vwGweYtt6mTJMEafZSJ30HvJ5lgX8xvVpeDrY2c7F
OgXj+6k4WwKBgQD3o27uKa6/rcMvuqf0q2mInQksh+aOSZqpaQSPF5GUj+Xtqol6
FrlThdQaxrCrteDiBAav23TnKHESr5TkbQPOQbnnjz/RI9BHe1BIhnvI+2+A1KuU
LT3JHNDyxhrCFtzMaBYY9QYuzjU5fFgymanawP+havWRJIyNH4gLVmdqOQKBgF++
T+ggCI5WRoagL0gTYKVy2NAeGEPfCEOJkELGD/8o3+/uckN0YOKth9437c0Yth/M
5SGtXSDvHmbiaKbLW2yNavJPu4kGa09C1as9oDpqYXn7rzIzsV8SLEWJEZFe49Rt
O33DiIIimD7jrMGmnv/VEUnkHbrFXJC4bbkLWW4dAoGAQOO8DhwN4ms2zm61RMM+
D92Ac4+2UD1P8PuH3i6ejUyZcf2sA6jtxpJjpAVzEM0E3e14eYhm++qjuwAkDkPx
HVhTANu8SZ06a4lMWpXf4AThQ+qFayx7chRGf9Y/Tq1vBNcZddf8ZClfQvHILGXg
rEDd48fCFV8ydGJdj0pi0AA=
-----END PRIVATE KEY-----
PEM;
        $pub = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAs/pH0OFNL+LXXPb6HJa/
Zlm35hapqkdP19zMAY9YaW/tpW/NvmB3+4oui1/b1PWaEd7vbM2TP/POb8ZTKUmZ
a5T0TFMaBylrQxIAYck+cER4lbMJq+6KZwj8AOm2EZ7CoMzdDr2J2KCb4UFQzMI0
Uj//8ZbiHZCrudSo3W5ovdm2AIG3L/g6okcwv2cx4N3jNk2U1hfiOlvmMrXSS4dP
Qi/Hv8aelXLrpBMYfTz/xHEVfPK9iR8mZnqdIiz5tWr+7To1XSw26cKBB4f+83yO
gv55WprP02z9NNt+NV6KDjxHxBn7eT2dQwo3C59y9NvvE+Jxrs0OoPteg59CejmM
xQIDAQAB
-----END PUBLIC KEY-----
PEM;

        return [$priv, $pub];
    }

    private function signedRequest(string $body, string $signature): Request
    {
        return Request::create('/webhooks/chip', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature,
        ], $body);
    }
}
