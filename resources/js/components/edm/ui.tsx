import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, CircleHelp, XCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * The EDM workspace's shared pieces, so every screen in the group reads as one
 * product: the same header, cards, numbers and status language.
 */

export function EdmHeader({ title, description, actions, back }: {
    title: React.ReactNode;
    description?: React.ReactNode;
    actions?: React.ReactNode;
    back?: { href: string; label: string };
}) {
    return (
        <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div className="min-w-0">
                {back && (
                    <Link href={back.href} className="mb-1 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="size-3.5" /> {back.label}
                    </Link>
                )}
                <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
                {description && <p className="mt-0.5 max-w-2xl text-sm text-muted-foreground">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

export function Section({ title, description, actions, children, className = '', padded = true }: {
    title?: React.ReactNode;
    description?: React.ReactNode;
    actions?: React.ReactNode;
    children: React.ReactNode;
    className?: string;
    padded?: boolean;
}) {
    return (
        <section className={`rounded-2xl border border-border bg-card shadow-sm ${padded ? 'p-5' : ''} ${className}`}>
            {(title || actions) && (
                <div className={`flex flex-wrap items-start justify-between gap-2 ${padded ? 'mb-4' : 'border-b border-border p-4'}`}>
                    <div className="min-w-0">
                        {title && <h2 className="text-sm font-semibold">{title}</h2>}
                        {description && <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>}
                    </div>
                    {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
                </div>
            )}
            {children}
        </section>
    );
}

export function Stat({ icon: Icon, label, value, hint, tint }: { icon: LucideIcon; label: string; value: React.ReactNode; hint?: React.ReactNode; tint: string }) {
    return (
        <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
            <span className="flex size-9 items-center justify-center rounded-xl" style={{ backgroundColor: `${tint}1f`, color: tint }}><Icon className="size-4" /></span>
            <div className="mt-3 text-2xl font-bold tabular-nums tracking-tight">{value}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
            {hint && <div className="mt-1 text-[11px] text-muted-foreground">{hint}</div>}
        </div>
    );
}

const STATUS: Record<string, { label: string; cls: string; icon: LucideIcon }> = {
    pass: { label: 'OK', cls: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400', icon: CheckCircle2 },
    clean: { label: 'Clean', cls: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400', icon: CheckCircle2 },
    good: { label: 'Good', cls: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400', icon: CheckCircle2 },
    warn: { label: 'Check', cls: 'bg-amber-500/10 text-amber-700 dark:text-amber-400', icon: AlertTriangle },
    fair: { label: 'Fair', cls: 'bg-amber-500/10 text-amber-700 dark:text-amber-400', icon: AlertTriangle },
    unknown: { label: 'Unknown', cls: 'bg-muted text-muted-foreground', icon: CircleHelp },
    fail: { label: 'Fix', cls: 'bg-rose-500/10 text-rose-700 dark:text-rose-400', icon: XCircle },
    poor: { label: 'Poor', cls: 'bg-rose-500/10 text-rose-700 dark:text-rose-400', icon: XCircle },
    listed: { label: 'Listed', cls: 'bg-rose-500/10 text-rose-700 dark:text-rose-400', icon: XCircle },
};

export function StatusPill({ status, label }: { status: string; label?: string }) {
    const s = STATUS[status] ?? STATUS.unknown;
    const Icon = s.icon;

    return (
        <span className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${s.cls}`}>
            <Icon className="size-3" /> {label ?? s.label}
        </span>
    );
}

export const pct = (n: number | null | undefined) => (n === null || n === undefined ? '—' : `${n}%`);

export const field = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

/** Pagination footer shared by the EDM lists. */
export function Pager({ prev, next, page, last }: { prev: string | null; next: string | null; page: number; last: number }) {
    if (!prev && !next) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-2 border-t border-border p-3 text-sm">
            <span className="text-muted-foreground">Page {page} of {last}</span>
            <div className="flex gap-2">
                {prev ? <Link href={prev} preserveScroll className="rounded-lg border border-border px-3 py-1.5 hover:bg-accent">← Prev</Link> : <span className="rounded-lg border border-border px-3 py-1.5 opacity-40">← Prev</span>}
                {next ? <Link href={next} preserveScroll className="rounded-lg border border-border px-3 py-1.5 hover:bg-accent">Next →</Link> : <span className="rounded-lg border border-border px-3 py-1.5 opacity-40">Next →</span>}
            </div>
        </div>
    );
}

export const EDM_CRUMB = { title: 'EDM', href: '/admin/edm' };
