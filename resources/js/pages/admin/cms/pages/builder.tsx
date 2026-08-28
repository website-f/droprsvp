import { Head, Link } from '@inertiajs/react';
import { Puck, usePuck  } from '@measured/puck';
import type {Data} from '@measured/puck';
import '@measured/puck/puck.css';
import { ArrowLeft, Eye } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { config, emptyData } from '@/components/cms/puck-config';
import { Button } from '@/components/ui/button';

interface PageProp { id: number; title: string; slug: string; status: string; data: Data | null }

function cookie(name: string): string | undefined {
    return document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))?.split('=')[1];
}

/** Back / Preview / Save — replaces Puck's default Publish button. */
function HeaderActions({ page }: { page: PageProp }) {
    'use no memo';
    const { appState } = usePuck();
    const [saving, setSaving] = useState(false);

    const persist = async (): Promise<boolean> => {
        setSaving(true);

        try {
            const res = await fetch(`/admin/cms/pages/${page.id}/builder`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie('XSRF-TOKEN') ?? '') },
                credentials: 'same-origin',
                body: JSON.stringify({ data: appState.data }),
            });

            if (!res.ok) {
throw new Error();
}

            return true;
        } catch {
            toast.error('Save failed — please try again.');

            return false;
        } finally {
            setSaving(false);
        }
    };

    const save = async () => {
 if (await persist()) {
toast.success('Saved');
} 
};
    const preview = async () => {
 if (await persist()) {
window.open(`/admin/cms/pages/${page.id}/preview`, '_blank', 'noopener');
} 
};

    return (
        <div className="flex items-center gap-2">
            <Button asChild variant="ghost" size="sm">
                <Link href={`/admin/cms/pages/${page.id}/edit`}><ArrowLeft className="size-4" /> Settings</Link>
            </Button>
            <Button variant="outline" size="sm" onClick={preview} disabled={saving}><Eye className="size-4" /> Preview</Button>
            <Button size="sm" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save'}</Button>
        </div>
    );
}

export default function DropBuilder({ page }: { page: PageProp }) {
    'use no memo';

    return (
        <>
            <Head title={`Builder · ${page.title}`} />
            <div className="h-screen">
                <Puck
                    config={config}
                    data={page.data ?? emptyData}
                    headerTitle={page.title}
                    headerPath={`/${page.slug}`}
                    onPublish={() => {}}
                    overrides={{ headerActions: () => <HeaderActions page={page} /> }}
                />
            </div>
        </>
    );
}
