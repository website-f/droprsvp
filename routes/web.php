<?php

use App\Http\Controllers\Admin\CmsPageController;
use App\Http\Controllers\Admin\CmsPostController;
use App\Http\Controllers\Admin\EventsController as AdminEventsController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Admin\PayoutController as AdminPayoutController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Host\PayoutController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Public\BlogController;
use App\Http\Controllers\Public\DiscoverController;
use App\Http\Controllers\Public\PageController as PublicPageController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\Host\CheckInController;
use App\Http\Controllers\Host\EventController;
use App\Http\Controllers\Host\OrderController;
use App\Http\Controllers\Host\SeatingController;
use App\Http\Controllers\Public\EventController as PublicEventController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Public event discovery / marketplace (SEO).
Route::get('events', [DiscoverController::class, 'index'])->name('events.browse');

// Public, server-rendered event page (SEO).
Route::get('e/{event}', [PublicEventController::class, 'show'])->name('events.show');

// Public blog (SEO).
Route::get('blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('blog/{post:slug}', [BlogController::class, 'show'])->name('blog.show');

// SEO plumbing.
Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('robots.txt', function () {
    return response("User-agent: *\nAllow: /\nSitemap: ".url('/sitemap.xml')."\n", 200)
        ->header('Content-Type', 'text/plain');
})->name('robots');

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
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Image uploads (event covers, CMS media) — any signed-in user.
    Route::post('uploads', [MediaController::class, 'store'])->name('uploads');

    // Buyer account — purchase history + re-download/re-send tickets.
    Route::get('my/tickets', [AccountController::class, 'tickets'])->name('account.tickets');
    Route::post('my/orders/{order}/resend', [AccountController::class, 'resend'])->name('account.orders.resend');

    // Host panel — manage your events, ticket types and sessions.
    Route::prefix('host')->name('host.')->group(function () {
        Route::get('events', [EventController::class, 'index'])->name('events.index');
        Route::get('events/create', [EventController::class, 'create'])->name('events.create');
        Route::post('events', [EventController::class, 'store'])->name('events.store');
        Route::get('events/{event}/edit', [EventController::class, 'edit'])->name('events.edit');
        Route::put('events/{event}', [EventController::class, 'update'])->name('events.update');
        Route::delete('events/{event}', [EventController::class, 'destroy'])->name('events.destroy');

        // Door check-in console.
        Route::get('events/{event}/checkin', [CheckInController::class, 'index'])->name('events.checkin');
        Route::post('events/{event}/checkin', [CheckInController::class, 'scan'])->name('events.checkin.scan');

        // Orders + refunds.
        Route::get('events/{event}/orders', [OrderController::class, 'index'])->name('events.orders');
        Route::post('events/{event}/orders/{order}/refund', [OrderController::class, 'refund'])->name('events.orders.refund');

        // Seating & table management.
        Route::get('events/{event}/seating', [SeatingController::class, 'index'])->name('events.seating');
        Route::post('events/{event}/seating/tables', [SeatingController::class, 'saveTables'])->name('events.seating.tables');
        Route::post('events/{event}/seating/assign', [SeatingController::class, 'assign'])->name('events.seating.assign');

        // Payouts (the organizer's own balance + requests).
        Route::get('payouts', [PayoutController::class, 'index'])->name('payouts.index');
        Route::post('payouts', [PayoutController::class, 'store'])->name('payouts.request');
    });

    // Headless CMS — superadmin only.
    Route::middleware('role:superadmin')->prefix('admin/cms')->name('admin.cms.')->group(function () {
        Route::get('pages', [CmsPageController::class, 'index'])->name('pages.index');
        Route::get('pages/create', [CmsPageController::class, 'create'])->name('pages.create');
        Route::post('pages', [CmsPageController::class, 'store'])->name('pages.store');
        Route::get('pages/{page:id}/edit', [CmsPageController::class, 'edit'])->name('pages.edit');
        Route::put('pages/{page:id}', [CmsPageController::class, 'update'])->name('pages.update');
        Route::delete('pages/{page:id}', [CmsPageController::class, 'destroy'])->name('pages.destroy');

        // Navigation menu (what shows in the public site header).
        Route::get('menu', [MenuController::class, 'index'])->name('menu.index');
        Route::post('menu', [MenuController::class, 'store'])->name('menu.store');
        Route::post('menu/reorder', [MenuController::class, 'reorder'])->name('menu.reorder');
        Route::put('menu/{menuItem}', [MenuController::class, 'update'])->name('menu.update');
        Route::delete('menu/{menuItem}', [MenuController::class, 'destroy'])->name('menu.destroy');

        Route::get('posts', [CmsPostController::class, 'index'])->name('posts.index');
        Route::get('posts/create', [CmsPostController::class, 'create'])->name('posts.create');
        Route::post('posts', [CmsPostController::class, 'store'])->name('posts.store');
        Route::get('posts/{post:id}/edit', [CmsPostController::class, 'edit'])->name('posts.edit');
        Route::put('posts/{post:id}', [CmsPostController::class, 'update'])->name('posts.update');
        Route::delete('posts/{post:id}', [CmsPostController::class, 'destroy'])->name('posts.destroy');
    });

    // Superadmin — cross-org platform administration.
    Route::middleware('role:superadmin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('overview', [AdminOverviewController::class, 'index'])->name('overview');
        Route::post('settings/fee', [AdminOverviewController::class, 'updateFee'])->name('settings.fee');
        Route::get('all-events', [AdminEventsController::class, 'index'])->name('events.index');
        Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('users/{user}/superadmin', [AdminUserController::class, 'toggleSuperadmin'])->name('users.superadmin');

        Route::get('payouts', [AdminPayoutController::class, 'index'])->name('payouts.index');
        Route::post('payouts/{payout}/paid', [AdminPayoutController::class, 'markPaid'])->name('payouts.paid');
    });
});

require __DIR__.'/settings.php';

// CMS pages at their own root slug — declared LAST so it only catches URLs no
// other route matched. Server-rendered for SEO.
Route::fallback([PublicPageController::class, 'show']);
