import { usePage } from '@inertiajs/react';
import { Megaphone, X } from 'lucide-react';
import { useState } from 'react';

interface Announcement {
    active: boolean; style: 'banner' | 'modal'; level: 'info' | 'success' | 'warning';
    title: string; body: string; cta_label: string; cta_url: string; version: number;
}

/**
 * Site-wide announcement — a dismissible banner or a first-load modal, set by a
 * superadmin under Admin → Settings. Dismissal is remembered per `version`, so an
 * edited announcement re-appears for everyone.
 */
export function SiteAnnouncement() {
    const a = usePage().props.announcement as Announcement | undefined;
    const key = a ? `dr_announce_v${a.version}` : '';
    const [dismissed, setDismissed] = useState(() =>
        typeof window !== 'undefined' && a?.active ? localStorage.getItem(key) === '1' : true,
    );

    if (!a?.active || dismissed || (!a.title && !a.body)) {
        return null;
    }

    const close = () => {
        if (typeof window !== 'undefined') {
            localStorage.setItem(key, '1');
        }

        setDismissed(true);
    };

    const tone = a.level === 'warning'
        ? 'border-amber-500/40 bg-amber-500/10'
        : a.level === 'success'
            ? 'border-emerald-500/40 bg-emerald-500/10'
            : 'border-primary/30 bg-primary/5';

    if (a.style === 'modal') {
        return (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={close}>
                <div className="w-full max-w-md rounded-2xl border border-border bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
                    <div className="flex items-start justify-between gap-3">
                        <h2 className="flex items-center gap-2 text-lg font-bold"><Megaphone className="size-5 shrink-0 text-primary" /> {a.title}</h2>
                        <button type="button" onClick={close} aria-label="Dismiss" className="shrink-0 text-muted-foreground hover:text-foreground"><X className="size-5" /></button>
                    </div>
                    {a.body && <p className="mt-2 whitespace-pre-line text-sm text-muted-foreground">{a.body}</p>}
                    {a.cta_label && a.cta_url && <a href={a.cta_url} className="mt-4 inline-flex rounded-full bg-foreground px-4 py-2 text-sm font-semibold text-background">{a.cta_label}</a>}
                </div>
            </div>
        );
    }

    return (
        <div className={`border-b ${tone}`}>
            <div className="mx-auto flex max-w-6xl items-center gap-3 px-6 py-2.5 text-sm">
                <Megaphone className="size-4 shrink-0" />
                <div className="min-w-0 flex-1"><span className="font-semibold">{a.title}</span>{a.body && <span className="text-muted-foreground"> — {a.body}</span>}</div>
                {a.cta_label && a.cta_url && <a href={a.cta_url} className="shrink-0 font-semibold underline underline-offset-2">{a.cta_label}</a>}
                <button type="button" onClick={close} aria-label="Dismiss" className="shrink-0 text-muted-foreground hover:text-foreground"><X className="size-4" /></button>
            </div>
        </div>
    );
}
