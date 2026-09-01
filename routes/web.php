<?php

use App\Http\Controllers\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Admin\ContactController as AdminContactController;
use App\Http\Controllers\Admin\CmsPageController;
use App\Http\Controllers\Admin\CmsCategoryController;
use App\Http\Controllers\Admin\CmsPostController;
use App\Http\Controllers\Admin\EventCategoryController;
use App\Http\Controllers\Admin\EventSeoController;
use App\Http\Controllers\Admin\EventsController as AdminEventsController;
use App\Http\Controllers\Admin\LegalController as AdminLegalController;
use App\Http\Controllers\Admin\OrganizerController as AdminOrganizerController;
use App\Http\Controllers\Host\OrganizerApplicationController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Admin\PayoutController as AdminPayoutController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\SiteController as AdminSiteController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Host\PayoutController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\OrganizerSignupController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\AboutYouController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\Public\ContactController;
use App\Http\Controllers\Public\EventCommentController;
use App\Http\Controllers\Public\EventReviewController;
use App\Http\Controllers\Public\OrganizerController;
use App\Http\Controllers\Public\SearchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Public\BlogController;
use App\Http\Controllers\Public\DiscoverController;
use App\Http\Controllers\Public\HelpController;
use App\Http\Controllers\Admin\HelpController as AdminHelpController;
use App\Http\Controllers\Public\PageController as PublicPageController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\Host\AnalyticsController;
use App\Http\Controllers\Host\CheckInController;
use App\Http\Controllers\Host\EventController;
use App\Http\Controllers\Host\OrderController;
use App\Http\Controllers\Host\PromotionController;
use App\Http\Controllers\Host\SeatingController;
use App\Http\Controllers\Public\EventController as PublicEventController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Everything lives under a locale prefix (/en-my) so more locales (/en-sg, …)
// can be added later. The bare root just redirects to the default locale home.
Route::get('/', fn () => redirect('/en-my'))->name('home');

// Public, server-rendered event page (SEO) — canonical is locale-prefixed;
// the old /e/{slug} keeps working as an alias so existing links don't break.
// Declared before the {locale}/{city}/{category} discovery routes so it wins.
Route::get('en-my/e/{event}', [PublicEventController::class, 'show'])->name('events.show');
Route::get('e/{event}', [PublicEventController::class, 'show'])->name('events.show.legacy');

// Legacy /events?… → 301 to the canonical locale path (/en-my/all…).
Route::get('events', [DiscoverController::class, 'legacyRedirect'])->name('events.browse');

// Public organizer profile (all their events + follow) — pretty slug URL.
Route::get('o/{organizer:slug}', [OrganizerController::class, 'show'])->name('organizers.show');

// Global search autocomplete suggestions (JSON).
Route::get('search/suggest', [SearchController::class, 'suggest'])->name('search.suggest');

// Locale home = the marketing landing. Browse/discovery lives one level deeper:
// /en-my/all, /en-my/{city}, /en-my/all/{category}, /en-my/{city}/{category}.
Route::get('en-my', [HomeController::class, 'index'])->name('home.locale');
Route::get('{locale}/{city}', [DiscoverController::class, 'index'])->whereIn('locale', ['en-my'])->name('discover.city');
Route::get('{locale}/{city}/{category}', [DiscoverController::class, 'index'])->whereIn('locale', ['en-my'])->name('discover.city.category');

// Public blog (SEO).
Route::get('blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('blog/{post:slug}', [BlogController::class, 'show'])->name('blog.show');

// Public help center (SEO).
Route::get('help', [HelpController::class, 'index'])->name('help.index');
Route::get('help/{article}', [HelpController::class, 'show'])->name('help.show');

// Public contact form.
Route::get('contact', [ContactController::class, 'show'])->name('contact.show');
Route::post('contact', [ContactController::class, 'store'])->name('contact.store');

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
Route::post('webhooks/chip', [WebhookController::class, 'chip'])->name('webhooks.chip');
Route::post('webhooks/chip-send', [WebhookController::class, 'chipSend'])->name('webhooks.chip-send');
Route::post('webhooks/promotions', [WebhookController::class, 'promotions'])->name('promotions.webhook');
Route::post('webhooks/subscriptions', [WebhookController::class, 'subscriptions'])->name('subscriptions.webhook');

// Public ticket pass (the qr_token in the URL is the credential).
Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');

// Organizer sign-up (email → code → name), guests only.
Route::middleware('guest')->group(function () {
    Route::get('get-started', [OrganizerSignupController::class, 'start'])->name('organizer.start');
    Route::post('get-started/code', [OrganizerSignupController::class, 'sendCode'])->name('organizer.code');
    Route::post('get-started/verify', [OrganizerSignupController::class, 'verifyCode'])->name('organizer.verify');
    Route::post('get-started/complete', [OrganizerSignupController::class, 'complete'])->name('organizer.complete');

    // "Continue with Google" (OAuth 2.0).
    Route::get('auth/google/redirect', [\App\Http\Controllers\Auth\GoogleController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [\App\Http\Controllers\Auth\GoogleController::class, 'callback'])->name('google.callback');
});

Route::middleware(['auth', 'verified', \App\Http\Middleware\EnsureAboutYou::class])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Required "about you" profile for consumer accounts.
    Route::get('profile/about-you', [AboutYouController::class, 'edit'])->name('profile.about-you');
    Route::post('profile/about-you', [AboutYouController::class, 'update'])->name('profile.about-you.save');

    // Post-signup onboarding (skippable).
    Route::get('host/welcome', [OrganizerSignupController::class, 'welcome'])->name('organizer.welcome');
    Route::post('host/welcome', [OrganizerSignupController::class, 'saveOnboarding'])->name('organizer.onboarding');

    // Upgrade a signed-in free account to a vendor (organizer).
    Route::post('become-a-vendor', [OrganizerSignupController::class, 'becomeVendor'])->name('organizer.become');

    // Organizer application (submit business details → wait for approval). Role-gated
    // but NOT approval-gated, so applicants can reach them.
    Route::middleware('role:organizer|superadmin')->group(function () {
        Route::get('host/apply', [OrganizerApplicationController::class, 'show'])->name('host.apply');
        Route::post('host/apply', [OrganizerApplicationController::class, 'submit'])->name('host.apply.submit');
        Route::get('host/pending', [OrganizerApplicationController::class, 'pending'])->name('host.pending');
    });

    // Image uploads (event covers, CMS media) — any signed-in user.
    Route::post('uploads', [MediaController::class, 'store'])->name('uploads');

    // Premium membership.
    Route::get('premium', [MembershipController::class, 'show'])->name('premium');
    Route::post('premium/subscribe', [MembershipController::class, 'subscribe'])->name('premium.subscribe');
    Route::get('premium/return', [MembershipController::class, 'return'])->name('premium.return');

    // Event discussion — post a question / reply (premium or organizer).
    Route::post('e/{event}/comments', [EventCommentController::class, 'store'])->name('events.comments.store');

    // Event rating + review (attendees only).
    Route::post('e/{event}/reviews', [EventReviewController::class, 'store'])->name('events.reviews.store');

    // Follow organizers + the following feed.
    Route::post('organizers/{organizer}/follow', [FollowController::class, 'toggle'])->name('organizers.follow');
    Route::get('following', [FollowController::class, 'index'])->name('following');

    // Buyer account — purchase history + re-download/re-send tickets.
    Route::get('my/tickets', [AccountController::class, 'tickets'])->name('account.tickets');
    Route::post('my/orders/{order}/resend', [AccountController::class, 'resend'])->name('account.orders.resend');

    // Host panel — manage your events, ticket types and sessions.
    // Hard-gated to vendors: a free attendee account must upgrade (become a
    // vendor) before it can create or manage events.
    Route::middleware(['role:organizer|superadmin', \App\Http\Middleware\EnsureOrganizerApproved::class])->prefix('host')->name('host.')->group(function () {
        // Analytics across all the organizer's events (links out to each event's own).
        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics');

        Route::get('events', [EventController::class, 'index'])->name('events.index');
        Route::get('events/create', [EventController::class, 'create'])->name('events.create');
        Route::post('events', [EventController::class, 'store'])->name('events.store');
        Route::get('events/{event}/edit', [EventController::class, 'edit'])->name('events.edit');
        Route::put('events/{event}', [EventController::class, 'update'])->name('events.update');
        Route::delete('events/{event}', [EventController::class, 'destroy'])->name('events.destroy');

        // Reusable seating templates.
        Route::post('seat-templates', [\App\Http\Controllers\Host\SeatTemplateController::class, 'store'])->name('seat-templates.store');
        Route::delete('seat-templates/{seatTemplate}', [\App\Http\Controllers\Host\SeatTemplateController::class, 'destroy'])->name('seat-templates.destroy');

        // Per-event analytics (reach, sales, audience demographics).
        Route::get('events/{event}/analytics', [AnalyticsController::class, 'show'])->name('events.analytics');

        // Promote / boost an event.
        Route::get('events/{event}/promote', [PromotionController::class, 'create'])->name('events.promote');
        Route::post('events/{event}/promote', [PromotionController::class, 'store'])->name('events.promote.store');
        Route::get('events/{event}/promote/return', [PromotionController::class, 'return'])->name('events.promote.return');

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
        Route::post('payouts/bank', [PayoutController::class, 'bank'])->name('payouts.bank');
    });

    // Headless CMS — superadmin only.
    Route::middleware('role:superadmin')->prefix('admin/cms')->name('admin.cms.')->group(function () {
        Route::get('pages', [CmsPageController::class, 'index'])->name('pages.index');
        Route::get('pages/create', [CmsPageController::class, 'create'])->name('pages.create');
        Route::post('pages', [CmsPageController::class, 'store'])->name('pages.store');
        Route::get('pages/{page:id}/edit', [CmsPageController::class, 'edit'])->name('pages.edit');
        Route::get('pages/{page:id}/builder', [CmsPageController::class, 'builder'])->name('pages.builder');
        Route::post('pages/{page:id}/builder', [CmsPageController::class, 'saveBuilder'])->name('pages.builder.save');
        Route::get('pages/{page:id}/preview', [CmsPageController::class, 'preview'])->name('pages.preview');
        Route::put('pages/{page:id}', [CmsPageController::class, 'update'])->name('pages.update');
        Route::delete('pages/{page:id}', [CmsPageController::class, 'destroy'])->name('pages.destroy');

        // Navigation menu (what shows in the public site header).
        Route::get('menu', [MenuController::class, 'index'])->name('menu.index');
        Route::post('menu', [MenuController::class, 'store'])->name('menu.store');
        Route::post('menu/reorder', [MenuController::class, 'reorder'])->name('menu.reorder');
        Route::put('menu/{menuItem}', [MenuController::class, 'update'])->name('menu.update');
        Route::delete('menu/{menuItem}', [MenuController::class, 'destroy'])->name('menu.destroy');

        // Help center articles.
        Route::get('help', [AdminHelpController::class, 'index'])->name('help.index');
        Route::get('help/create', [AdminHelpController::class, 'create'])->name('help.create');
        Route::post('help', [AdminHelpController::class, 'store'])->name('help.store');
        Route::get('help/{help:id}/edit', [AdminHelpController::class, 'edit'])->name('help.edit');
        Route::put('help/{help:id}', [AdminHelpController::class, 'update'])->name('help.update');
        Route::delete('help/{help:id}', [AdminHelpController::class, 'destroy'])->name('help.destroy');

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
        Route::get('analytics', [AdminAnalyticsController::class, 'index'])->name('analytics');
        Route::get('analytics/export', [AdminAnalyticsController::class, 'export'])->name('analytics.export');
        Route::get('analytics/{event}', [AdminAnalyticsController::class, 'show'])->name('analytics.show');
        // Central platform settings (fees, tax, general) — tabbed.
        Route::get('settings', [AdminSettingsController::class, 'index'])->name('settings');
        Route::post('settings', [AdminSettingsController::class, 'update'])->name('settings.save');

        // Event categories (used across discovery + event creation).
        Route::get('categories', [EventCategoryController::class, 'index'])->name('categories.index');
        Route::post('categories', [EventCategoryController::class, 'store'])->name('categories.store');
        Route::post('categories/browse-seo', [EventCategoryController::class, 'saveBrowseSeo'])->name('categories.browse-seo');
        Route::post('categories/reorder', [EventCategoryController::class, 'reorder'])->name('categories.reorder');
        Route::put('categories/{category}', [EventCategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [EventCategoryController::class, 'destroy'])->name('categories.destroy');

        // Post (blog) categories — managed on the same tabbed page.
        Route::post('post-categories', [CmsCategoryController::class, 'store'])->name('post-categories.store');
        Route::put('post-categories/{cmsCategory}', [CmsCategoryController::class, 'update'])->name('post-categories.update');
        Route::delete('post-categories/{cmsCategory}', [CmsCategoryController::class, 'destroy'])->name('post-categories.destroy');

        // Per-event SEO manager.
        Route::get('seo/events', [EventSeoController::class, 'index'])->name('seo.events');
        Route::get('seo/events/{event}', [EventSeoController::class, 'edit'])->name('seo.events.edit');
        Route::put('seo/events/{event}', [EventSeoController::class, 'update'])->name('seo.events.update');
        Route::get('all-events', [AdminEventsController::class, 'index'])->name('events.index');
        Route::get('all-events/{event}', [AdminEventsController::class, 'show'])->name('events.show');
        Route::post('all-events/{event}/cancel', [AdminEventsController::class, 'cancel'])->name('events.cancel');
        Route::post('all-events/{event}/restore', [AdminEventsController::class, 'restore'])->name('events.restore');
        // Organizer/vendor applications.
        Route::get('organizers', [AdminOrganizerController::class, 'index'])->name('organizers.index');
        Route::get('organizers/{organizer}', [AdminOrganizerController::class, 'show'])->name('organizers.show');
        Route::post('organizers/{organizer}/approve', [AdminOrganizerController::class, 'approve'])->name('organizers.approve');
        Route::post('organizers/{organizer}/reject', [AdminOrganizerController::class, 'reject'])->name('organizers.reject');

        Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('users/export', [AdminUserController::class, 'export'])->name('users.export');
        Route::get('users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::post('users/{user}/superadmin', [AdminUserController::class, 'toggleSuperadmin'])->name('users.superadmin');

        Route::get('payouts', [AdminPayoutController::class, 'index'])->name('payouts.index');
        Route::post('payouts/{payout}/paid', [AdminPayoutController::class, 'markPaid'])->name('payouts.paid');
        Route::post('payouts/{payout}/send', [AdminPayoutController::class, 'send'])->name('payouts.send');
        Route::post('payouts/{payout}/sync', [AdminPayoutController::class, 'sync'])->name('payouts.sync');

        // Contact-form inbox.
        Route::get('contact', [AdminContactController::class, 'index'])->name('contact.index');
        Route::post('contact/{message}/toggle', [AdminContactController::class, 'toggle'])->name('contact.toggle');

        // Site appearance — landing sections + footer builder.
        Route::get('site/landing', [AdminSiteController::class, 'landing'])->name('site.landing');
        Route::post('site/landing', [AdminSiteController::class, 'saveLanding'])->name('site.landing.save');
        Route::get('site/footer', [AdminSiteController::class, 'footer'])->name('site.footer');
        Route::post('site/footer', [AdminSiteController::class, 'saveFooter'])->name('site.footer.save');
        // Branding — logo (wordmark + mark) upload/remove + per-surface sizing.
        Route::get('site/branding', [AdminSiteController::class, 'branding'])->name('site.branding');
        Route::post('site/branding', [AdminSiteController::class, 'saveBranding'])->name('site.branding.save');
        // Homepage SEO — the landing page is premade, so only its SEO is editable.
        Route::get('site/home-seo', [AdminSiteController::class, 'homeSeo'])->name('site.home-seo');
        Route::post('site/home-seo', [AdminSiteController::class, 'saveHomeSeo'])->name('site.home-seo.save');
        // Legal pages — Privacy Policy + Terms (rich text; live at /privacy-policy, /terms).
        Route::get('site/legal', [AdminLegalController::class, 'edit'])->name('site.legal');
        Route::post('site/legal', [AdminLegalController::class, 'update'])->name('site.legal.save');
    });
});

require __DIR__.'/settings.php';

// CMS pages at their own root slug — declared LAST so it only catches URLs no
// other route matched. Server-rendered for SEO.
Route::fallback([PublicPageController::class, 'show']);
