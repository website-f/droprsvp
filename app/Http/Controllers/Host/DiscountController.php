<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiscountController extends Controller
{
    /** List an event's promo codes, each with its redemption + revenue analytics. */
    public function index(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        return inertia('host/events/discounts', [
            'event' => ['title' => $event->title, 'slug' => $event->slug, 'currency' => 'MYR'],
            'codes' => $event->discountCodes()->latest()->get()->map(fn (DiscountCode $c) => $this->row($c)),
        ]);
    }

    public function store(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $data = $this->validated($request, $event);
        $event->discountCodes()->create($data);

        return back()->with('flash_success', 'Promo code created.');
    }

    public function update(Request $request, Event $event, DiscountCode $discount)
    {
        $this->authorize('update', $event);
        abort_unless($discount->event_id === $event->id, 404);

        $discount->update($this->validated($request, $event, $discount->id));

        return back()->with('flash_success', 'Promo code updated.');
    }

    public function destroy(Request $request, Event $event, DiscountCode $discount)
    {
        $this->authorize('update', $event);
        abort_unless($discount->event_id === $event->id, 404);

        $discount->delete();

        return back()->with('flash_success', 'Promo code deleted.');
    }

    private function validated(Request $request, Event $event, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('discount_codes', 'code')->where('event_id', $event->id)->ignore($ignoreId)],
            'kind' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'min:0.01', $request->input('kind') === 'percent' ? 'max:100' : 'max:1000000'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['boolean'],
        ]);
        $data['code'] = strtoupper($data['code']);

        return $data;
    }

    private function row(DiscountCode $c): array
    {
        $settled = Order::where('discount_code_id', $c->id)->whereIn('status', ['paid', 'refunded']);

        return [
            'id' => $c->id,
            'code' => $c->code,
            'kind' => $c->kind,
            'value' => (float) $c->value,
            'min_subtotal' => $c->min_subtotal !== null ? (float) $c->min_subtotal : null,
            'max_redemptions' => $c->max_redemptions,
            'is_active' => $c->is_active,
            'starts_at' => optional($c->starts_at)->toDateString(),
            'ends_at' => optional($c->ends_at)->toDateString(),
            'stats' => [
                'redemptions' => (clone $settled)->count(),
                'revenue' => (float) (clone $settled)->sum('total'),
                'discount_given' => (float) (clone $settled)->sum('discount'),
            ],
        ];
    }
}
