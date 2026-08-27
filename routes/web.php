<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Host\EventController;
use App\Http\Controllers\Public\EventController as PublicEventController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Public, server-rendered event page (SEO).
Route::get('e/{event}', [PublicEventController::class, 'show'])->name('events.show');

// Checkout (guest-friendly). `checkout/return` is declared before `checkout/{order}`
// so the literal path wins over the {order} binding.
Route::post('e/{event}/checkout', [CheckoutController::class, 'start'])->name('checkout.start');
Route::get('checkout/return', [CheckoutController::class, 'return'])->name('checkout.return');
Route::get('checkout/{order}', [CheckoutController::class, 'show'])->name('checkout.show');
Route::post('checkout/{order}/pay', [CheckoutController::class, 'pay'])->name('checkout.pay');
Route::get('checkout/{order}/fake-pay', [CheckoutController::class, 'fake'])->name('checkout.fake');
Route::get('orders/{order}', [CheckoutController::class, 'confirmation'])->name('checkout.confirmation');
Route::post('webhooks/hitpay', [WebhookController::class, 'hitpay'])->name('webhooks.hitpay');

// Public ticket pass (the qr_token in the URL is the credential).
Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Host panel — manage your events, ticket types and sessions.
    Route::prefix('host')->name('host.')->group(function () {
        Route::get('events', [EventController::class, 'index'])->name('events.index');
        Route::get('events/create', [EventController::class, 'create'])->name('events.create');
        Route::post('events', [EventController::class, 'store'])->name('events.store');
        Route::get('events/{event}/edit', [EventController::class, 'edit'])->name('events.edit');
        Route::put('events/{event}', [EventController::class, 'update'])->name('events.update');
        Route::delete('events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
    });
});

require __DIR__.'/settings.php';
