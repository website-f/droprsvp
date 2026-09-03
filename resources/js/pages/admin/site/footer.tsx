import { Head, Link } from '@inertiajs/react';
import { Puck, usePuck  } from '@measured/puck';
import type {Data} from '@measured/puck';
import '@measured/puck/puck.css';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { emptyFooterData, footerConfig } from '@/components/cms/footer-puck-config';
import { Button } from '@/components/ui/button';

function cookie(name: string): string | undefined {
    return document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))?.split('=')[1];
}

function HeaderActions() {
    'use no memo';
    const { appState } = usePuck();
    const [saving, setSaving] = useState(false);

    const save = async () => {
        setSaving(true);

        try {
            const res = await fetch('/admin/site/footer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie('XSRF-TOKEN') ?? '') },
                credentials: 'same-origin',
                body: JSON.stringify({ data: appState.data }),
            });

            if (!res.ok) {
throw new Error();
}

            toast.success('Footer saved');
        } catch {
            toast.error('Save failed — please try again.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="flex items-center gap-2">
            <Button asChild variant="ghost" size="sm"><Link href="/admin/overview"><ArrowLeft className="size-4" /> Admin</Link></Button>
            <Button size="sm" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save footer'}</Button>
        </div>
    );
}

export default function FooterEditor({ data }: { data: Data | null }) {
    'use no memo';

    return (
        <>
            <Head title="Footer editor" />
            <div className="h-screen">
                <Puck
                    config={footerConfig}
                    data={data ?? emptyFooterData}
                    headerTitle="Footer"
                    headerPath="site-wide"
                    iframe={{ waitForStyles: false }}
                    onPublish={() => {}}
                    overrides={{ headerActions: () => <HeaderActions /> }}
                />
            </div>
        </>
    );
}
