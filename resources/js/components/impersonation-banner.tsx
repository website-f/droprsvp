import { router } from '@inertiajs/react';
import { Eye, LogOut } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Impersonating {
    actor: string | null;
    viewing: string | null;
    viewing_email: string | null;
    role: string;
}

interface PageProps { impersonating?: Impersonating | null }

/**
 * The impersonation prop from the page Inertia mounted with.
 *
 * Read during render rather than in an effect: doing it in an effect means a
 * first paint without the banner and an immediate second render, which is what
 * react-hooks/set-state-in-effect objects to — and on a borrowed session the
 * banner is the one thing that should never flicker in late.
 */
function initialImpersonation(): Impersonating | null {
    try {
        const el = document.getElementById('app');

        return el?.dataset.page
            ? ((JSON.parse(el.dataset.page).props as PageProps).impersonating ?? null)
            : null;
    } catch {
        return null; // malformed payload — the banner simply stays hidden
    }
}

/**
 * The strip that says "you are not you right now".
 *
 * Deliberately impossible to dismiss and fixed to the bottom of the viewport,
 * because the failure mode of an impersonation feature is forgetting you are in
 * one — and then making a change, or reading a support ticket, as somebody
 * else. Bottom rather than top so it never collides with the sticky public
 * header or the panel's own toolbar on a phone.
 *
 * Mounted once at the app root, OUTSIDE the Inertia page tree, so it reads
 * props via the global router rather than usePage() — which throws
 * "usePage must be used within the Inertia component" out here. Same approach
 * as FlashWatcher, for the same reason: this has to survive every layout,
 * including the public and focused-wizard pages that render no layout at all.
 *
 * Renders nothing for an ordinary session.
 */
export function ImpersonationBanner() {
    const [impersonating, setImpersonating] = useState<Impersonating | null>(initialImpersonation);

    useEffect(() => {
        // Every navigation afterwards, including the ones that start and end
        // the impersonation.
        return router.on('navigate', (event) => {
            setImpersonating((event.detail.page.props as PageProps).impersonating ?? null);
        });
    }, []);

    if (!impersonating) {
        return null;
    }

    return (
        <div
            role="status"
            className="fixed inset-x-0 bottom-0 z-[90] border-t border-amber-500/40 bg-amber-500 text-amber-950 shadow-[0_-4px_16px_rgba(0,0,0,0.12)]"
        >
            <div className="mx-auto flex w-full max-w-5xl flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2.5 text-sm">
                <Eye className="size-4 shrink-0" aria-hidden />
                <span className="min-w-0 flex-1">
                    Viewing as <strong className="font-semibold">{impersonating.viewing ?? 'this user'}</strong>
                    <span className="hidden sm:inline"> ({impersonating.role})</span>
                    {impersonating.actor && <span className="hidden md:inline"> — signed in as {impersonating.actor}</span>}
                </span>
                <button
                    type="button"
                    onClick={() => router.post('/stop-impersonating')}
                    className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-amber-950 px-3 py-1.5 text-xs font-semibold text-amber-50 transition-colors hover:bg-amber-900"
                >
                    <LogOut className="size-3.5" /> Back to my account
                </button>
            </div>
        </div>
    );
}
