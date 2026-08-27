import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * Full-screen, WordPress-style editing surface: a sticky action bar on top,
 * a wide content column, and a settings rail that collapses below the content
 * on smaller screens. Used by the CMS page + post editors (no app sidebar).
 */
export function EditorShell({
    backHref,
    backLabel,
    title,
    status,
    actions,
    sidebar,
    children,
}: {
    backHref: string;
    backLabel: string;
    title: string;
    status?: ReactNode;
    actions: ReactNode;
    sidebar: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="flex min-h-screen flex-col bg-muted/30 text-foreground">
            <header className="sticky top-0 z-20 flex items-center gap-3 border-b border-border bg-card/95 px-4 py-3 backdrop-blur">
                <Button asChild variant="ghost" size="icon">
                    <Link href={backHref} aria-label={`Back to ${backLabel}`}><ArrowLeft className="size-4" /></Link>
                </Button>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold leading-tight">{title || 'Untitled'}</p>
                    <p className="text-xs text-muted-foreground">{backLabel}</p>
                </div>
                {status}
                <div className="flex items-center gap-2">{actions}</div>
            </header>

            <div className="mx-auto grid w-full max-w-screen-2xl flex-1 gap-6 p-4 lg:grid-cols-[minmax(0,1fr)_20rem] lg:p-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
                <main className="min-w-0">{children}</main>
                <aside className="min-w-0 lg:sticky lg:top-20 lg:h-fit">
                    <div className="grid gap-4">{sidebar}</div>
                </aside>
            </div>
        </div>
    );
}

/** A labelled settings card for the editor rail. */
export function SettingsCard({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</h2>
            <div className="grid gap-3">{children}</div>
        </section>
    );
}
