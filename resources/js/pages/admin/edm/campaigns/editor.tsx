import { Head, Link } from '@inertiajs/react';
import { Puck, usePuck } from '@measured/puck';
import type { Data } from '@measured/puck';
import '@measured/puck/puck.css';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { emailConfig } from '@/components/edm/email-puck-config';
import type { EventChoice } from '@/components/edm/email-puck-config';
import { Button } from '@/components/ui/button';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

interface Props {
    campaign: { id: number; name: string; editable: boolean };
    design: Data;
    events: EventChoice[];
}

function cookie(name: string): string | undefined {
    return document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))?.split('=')[1];
}

function HeaderActions({ campaign, onSaved }: { campaign: Props['campaign']; onSaved: (data: Data) => void }) {
    'use no memo';
    const { appState } = usePuck();
    const [saving, setSaving] = useState(false);

    const save = async () => {
        setSaving(true);

        try {
            const res = await fetch(`/admin/edm/campaigns/${campaign.id}/design`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie('XSRF-TOKEN') ?? '') },
                credentials: 'same-origin',
                body: JSON.stringify({ design: appState.data }),
            });

            if (!res.ok) {
                throw new Error();
            }

            onSaved(appState.data);
            toast.success('Email saved');
        } catch {
            toast.error('Save failed — please try again.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="flex items-center gap-2">
            <Button asChild variant="ghost" size="sm">
                <Link href={`/admin/edm/campaigns/${campaign.id}`}><ArrowLeft className="size-4" /> Campaign</Link>
            </Button>
            {campaign.editable && <Button size="sm" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save email'}</Button>}
        </div>
    );
}

/** Tracks whether the canvas differs from what was last saved. */
function DirtyWatcher({ saved, onDirty }: { saved: string; onDirty: (dirty: boolean) => void }) {
    'use no memo';
    const { appState } = usePuck();
    const isDirty = JSON.stringify(appState.data) !== saved;

    // In an effect, not during render: setting the parent's state while this
    // component renders is an error React warns about.
    useEffect(() => {
        onDirty(isDirty);
    }, [isDirty, onDirty]);

    return null;
}

export default function EmailEditor({ campaign, design, events }: Props) {
    'use no memo';
    const config = useMemo(() => emailConfig(events), [events]);
    const [saved, setSaved] = useState(() => JSON.stringify(design));
    const [dirty, setDirty] = useState(false);

    // Leaving the builder with unsaved blocks loses them; ask first.
    useUnsavedChanges(dirty && campaign.editable);

    return (
        <>
            <Head title={`Email · ${campaign.name}`} />
            <div className="h-screen">
                <Puck
                    config={config}
                    data={design}
                    headerTitle={campaign.name}
                    headerPath={campaign.editable ? 'email design' : 'sent — read only'}
                    iframe={{ waitForStyles: false }}
                    // Email-sized, not the site's 1280px desktop: an email is a
                    // 600px column, and fitting a desktop viewport into the
                    // canvas shrank it to an unreadable third of its size.
                    viewports={[
                        { width: 640, height: 'auto', label: 'Email', icon: 'Monitor' },
                        { width: 375, height: 'auto', label: 'Phone', icon: 'Smartphone' },
                    ]}
                    onPublish={() => {}}
                    overrides={{
                        headerActions: () => (
                            <>
                                <DirtyWatcher saved={saved} onDirty={setDirty} />
                                <HeaderActions campaign={campaign} onSaved={(d) => setSaved(JSON.stringify(d))} />
                            </>
                        ),
                    }}
                />
            </div>
        </>
    );
}
