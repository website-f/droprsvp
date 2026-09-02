import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { useConfirm } from '@/components/confirm-dialog';

/**
 * Guards a create/edit form against accidental data loss.
 *
 * While `enabled` (i.e. the form has unsaved changes and isn't currently saving):
 *  - a browser refresh / tab close / leaving the site shows the native "Leave site?"
 *    prompt (browsers don't allow a custom dialog there);
 *  - any in-app navigation away (link click, sidebar, programmatic visit) is paused
 *    and a themed "Discard unsaved changes?" dialog is shown — Keep editing cancels,
 *    Discard proceeds with the navigation. Nothing is published either way.
 *
 * The form's own save (POST/PUT) and in-place partial reloads are never blocked.
 */
export function useUnsavedChanges(enabled: boolean): void {
    const confirm = useConfirm();
    // Mirror `enabled` into a ref so the long-lived listeners below always read the
    // latest value without being torn down and re-registered on every keystroke.
    const enabledRef = useRef(enabled);
    useEffect(() => {
        enabledRef.current = enabled;
    }, [enabled]);
    // Set just before we re-issue a visit the user chose to discard into, so the
    // guard lets that one through instead of prompting again.
    const bypass = useRef(false);

    // Native guard for full-page unloads (refresh, close, external links).
    useEffect(() => {
        const onBeforeUnload = (e: BeforeUnloadEvent) => {
            if (!enabledRef.current) {
                return;
            }

            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', onBeforeUnload);

        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, []);

    // SPA guard for Inertia navigations away from the form.
    useEffect(() => {
        const off = router.on('before', (event) => {
            if (!enabledRef.current) {
                return;
            }

            const visit = event.detail.visit;

            // Never block the save itself (POST/PUT) or an in-place partial reload
            // (those keep the user on the same page).
            if (visit.method !== 'get' || (visit.only && visit.only.length > 0)) {
                return;
            }

            if (bypass.current) {
                bypass.current = false;

                return;
            }

            confirm({
                title: 'Discard unsaved changes?',
                description: 'You have changes that haven’t been saved yet. If you leave now they’ll be lost — nothing is published.',
                confirmText: 'Discard changes',
                cancelText: 'Keep editing',
                destructive: true,
            }).then((ok) => {
                if (ok) {
                    bypass.current = true;
                    router.visit(visit.url, {
                        method: visit.method,
                        replace: visit.replace,
                        preserveScroll: visit.preserveScroll,
                        preserveState: visit.preserveState,
                    });
                }
            });

            // Cancel the original navigation while we ask.
            return false;
        });

        return off;
    }, [confirm]);
}
