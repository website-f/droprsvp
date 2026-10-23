import { usePage } from '@inertiajs/react';

/**
 * What happens to JavaScript in pasted HTML, for whoever is editing.
 *
 * Decided at save time by who saves (CmsPageController::trusts): a
 * superadmin's page runs its scripts in the page itself, anyone else's runs
 * them in a sealed frame where they cannot reach the site.
 */
export function ScriptsNote({ className }: { className?: string }) {
    const { auth } = usePage().props as { auth?: { is_superadmin?: boolean } };

    return (
        <span className={className}>
            {auth?.is_superadmin
                ? 'JavaScript runs on the page, because a superadmin is saving it.'
                : 'JavaScript runs in a sealed frame, apart from the rest of the site.'}
        </span>
    );
}
