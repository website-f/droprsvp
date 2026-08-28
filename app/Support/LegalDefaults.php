<?php

namespace App\Support;

/**
 * Default content for the Privacy Policy and Terms & Conditions pages.
 * Seeded once and fully editable by the superadmin afterwards. Generic,
 * plain-language starting points — not a substitute for legal review.
 */
class LegalDefaults
{
    /** slug => title */
    public const PAGES = [
        'privacy-policy' => 'Privacy Policy',
        'terms' => 'Terms & Conditions',
    ];

    public static function body(string $slug): string
    {
        return match ($slug) {
            'privacy-policy' => self::privacy(),
            'terms' => self::terms(),
            default => '',
        };
    }

    private static function privacy(): string
    {
        $company = config('seo.organization.name', config('app.name'));

        return <<<HTML
<h2>1. Introduction</h2>
<p>This Privacy Policy explains how {$company} ("we", "us") collects, uses and protects your information when you use our event ticketing and discovery platform.</p>
<h2>2. Information we collect</h2>
<p>We collect information you provide when you create an account, buy tickets or host an event — such as your name, email address, and payment details processed by our payment provider. We also collect basic usage data to operate and improve the service.</p>
<h2>3. How we use your information</h2>
<p>We use your information to process orders and issue tickets, to send you order confirmations and event updates, to prevent fraud, and to comply with legal obligations.</p>
<h2>4. Sharing</h2>
<p>We share necessary information with event organizers for events you attend, and with payment and infrastructure providers who help us run the service. We do not sell your personal data.</p>
<h2>5. Data retention</h2>
<p>We keep your information for as long as your account is active or as needed to provide the service and meet our legal obligations.</p>
<h2>6. Your rights</h2>
<p>You may request access to, correction of, or deletion of your personal data by contacting us. You may also unsubscribe from marketing emails at any time.</p>
<h2>7. Contact</h2>
<p>For any privacy questions, please contact us through the details on our website.</p>
HTML;
    }

    private static function terms(): string
    {
        $company = config('seo.organization.name', config('app.name'));

        return <<<HTML
<h2>1. Agreement</h2>
<p>By using {$company}, you agree to these Terms &amp; Conditions. If you do not agree, please do not use the platform.</p>
<h2>2. Accounts</h2>
<p>You are responsible for keeping your account credentials secure and for all activity under your account. You must provide accurate information when registering.</p>
<h2>3. Buying tickets</h2>
<p>Ticket sales are between you and the event organizer. Prices, availability and refund terms are set by the organizer. Confirmed tickets are delivered electronically with a QR pass.</p>
<h2>4. Hosting events</h2>
<p>Organizers are responsible for the accuracy of their event listings and for delivering the events they publish. Listings must not be misleading, unlawful, or in breach of our content policy. We may remove or cancel any event that breaches these terms.</p>
<h2>5. Payments &amp; payouts</h2>
<p>Payments are processed by our payment provider. Platform fees, where applicable, are disclosed before checkout. Organizer payouts are made according to our payout schedule.</p>
<h2>6. Refunds</h2>
<p>Refunds are governed by the organizer's stated policy and applicable law. Where an event is cancelled, affected orders may be refunded.</p>
<h2>7. Prohibited use</h2>
<p>You may not use the platform for unlawful, fraudulent, or harmful activity, or to list prohibited goods or events.</p>
<h2>8. Liability</h2>
<p>The platform is provided "as is". To the extent permitted by law, we are not liable for the acts of organizers or attendees, or for indirect or consequential losses.</p>
<h2>9. Changes</h2>
<p>We may update these terms from time to time. Continued use of the platform after changes take effect constitutes acceptance.</p>
<h2>10. Contact</h2>
<p>Questions about these terms can be sent to us through the details on our website.</p>
HTML;
    }
}
