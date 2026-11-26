<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * A gateway the return and confirmation pages can ask directly whether an
 * order has been paid, instead of waiting on the webhook — the buyer is
 * usually back before it arrives.
 */
interface ConfirmsPayments
{
    public function isPaid(Order $order): bool;

    /**
     * What to record when settling from the return page.
     *
     * @return array{payment_ref: ?string, payment: array{method?: ?string, brand?: ?string}}
     */
    public function settlement(Order $order): array;
}
