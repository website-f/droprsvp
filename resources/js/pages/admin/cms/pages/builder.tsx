import { Head } from '@inertiajs/react';
import type { Data } from '@measured/puck';
import { Loader2 } from 'lucide-react';
import { lazy, Suspense } from 'react';
import type { BuilderPage } from '@/components/cms/builder-canvas';

// The Puck editor + widget config is heavy; load it behind a spinner so the
// route swaps in instantly instead of blocking on the download.
const BuilderCanvas = lazy(() => import('@/components/cms/builder-canvas'));

export default function DropBuilder({ page }: { page: BuilderPage & { data: Data | null } }) {
    return (
        <>
            <Head title={`Builder · ${page.title}`} />
            <Suspense
                fallback={(
                    <div className="flex h-screen flex-col items-center justify-center gap-3 text-muted-foreground">
                        <Loader2 className="size-6 animate-spin" />
                        <p className="text-sm">Loading the builder…</p>
                    </div>
                )}
            >
                <BuilderCanvas page={page} />
            </Suspense>
        </>
    );
}
