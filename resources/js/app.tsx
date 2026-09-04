import { createInertiaApp } from '@inertiajs/react';
import { ConfirmProvider } from '@/components/confirm-dialog';
import { FlashWatcher } from '@/components/flash-watcher';
import { PromptProvider } from '@/components/prompt-dialog';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    // Append the brand — but not when the page's title already contains it (e.g. an
    // admin-set SEO title like "… | Drop RSVP"), so we never double-brand the tab.
    title: (title) => {
        if (!title) {
            return appName;
        }

        const norm = (s: string) => s.toLowerCase().replace(/[^a-z0-9]/g, '');

        return norm(title).includes(norm(appName)) ? title : `${title} - ${appName}`;
    },
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name === 'admin/cms/pages/form':
            case name === 'admin/cms/posts/form':
            case name === 'admin/cms/pages/builder':
            case name === 'admin/site/footer':
                return null; // WordPress-style full-screen editor (own chrome)
            case name === 'auth/choose':
            case name === 'auth/get-started':
            case name === 'host/welcome':
            case name === 'profile/about-you':
            case name === 'host/apply':
            case name === 'host/pending':
                return null; // focused sign-up + onboarding wizard (own chrome)
            case name.startsWith('public/'):
            case name.startsWith('checkout/'):
            case name.startsWith('receipts/'):
                return null; // public + checkout + receipt pages carry their own chrome
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                <ConfirmProvider>
                    <PromptProvider>
                        {app}
                        <FlashWatcher />
                        <Toaster />
                    </PromptProvider>
                </ConfirmProvider>
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
