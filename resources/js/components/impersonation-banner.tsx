import { router, usePage } from '@inertiajs/react';
import { Eye, LogOut } from 'lucide-react';

interface Impersonating {
    actor: string | null;
    viewing: string | null;
    viewing_email: string | null;
    role: string;
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
 * Renders nothing at all for an ordinary session, so it costs a null check on
 * every page and nothing more.
 */
export function ImpersonationBanner() {
    const impersonating = usePage().props.impersonating as Impersonating | null | undefined;

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
