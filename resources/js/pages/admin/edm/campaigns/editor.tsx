import { Head, Link } from '@inertiajs/react';
import { Puck, usePuck } from '@measured/puck';
import type { Data } from '@measured/puck';
import '@measured/puck/puck.css';
import { ArrowLeft, Code2, ExternalLink, LayoutTemplate, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { emailConfig } from '@/components/edm/email-puck-config';
import type { EventChoice } from '@/components/edm/email-puck-config';
import { HtmlPreview } from '@/components/html-preview';
import { Button } from '@/components/ui/button';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

interface Props {
    campaign: { id: number; name: string; editable: boolean };
    /** Blocks, plus — when "HTML mode" has been used — the pasted email. */
    design: Data & { editor?: string; html?: string };
    events: EventChoice[];
    /** Set when editing a template (or an organizer's campaign) rather than an admin campaign. */
    saveUrl?: string;
    backUrl?: string;
    backLabel?: string;
    kind?: 'campaign' | 'template';
}

type Mode = 'builder' | 'html';
type Target = { saveUrl: string; previewUrl: string; backUrl: string; backLabel: string; editable: boolean };

function cookie(name: string): string | undefined {
    return document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))?.split('=')[1];
}

/** What the server stores, as a string — for "has anything changed since the last save?". */
const snapshot = (blocks: Data, mode: Mode, html: string) =>
    JSON.stringify({ root: blocks.root, content: blocks.content, editor: mode, html });

function ModeSwitch({ mode, onChange }: { mode: Mode; onChange: (mode: Mode) => void }) {
    const options: { value: Mode; label: string; icon: typeof Code2 }[] = [
        { value: 'builder', label: 'Drag & drop', icon: LayoutTemplate },
        { value: 'html', label: 'HTML', icon: Code2 },
    ];

    return (
        <div className="inline-flex rounded-lg border border-border bg-card p-0.5" role="group" aria-label="Email editor">
            {options.map(({ value, label, icon: Icon }) => (
                <button
                    key={value}
                    type="button"
                    aria-pressed={mode === value}
                    onClick={() => onChange(value)}
                    className={`flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${mode === value ? 'bg-foreground text-background' : 'text-muted-foreground hover:text-foreground'}`}
                >
                    <Icon className="size-3.5" /> {label}
                </button>
            ))}
        </div>
    );
}

/** Puck's header buttons in builder mode. Reads the canvas directly, so a save is never one edit behind. */
function BuilderActions({ target, mode, onMode, onSave, saving }: {
    target: Target; mode: Mode; onMode: (mode: Mode) => void; onSave: (blocks: Data) => void; saving: boolean;
}) {
    'use no memo';
    const { appState } = usePuck();

    return (
        <div className="flex items-center gap-2">
            <ModeSwitch mode={mode} onChange={onMode} />
            <Button asChild variant="ghost" size="sm">
                <Link href={target.backUrl}><ArrowLeft className="size-4" /> {target.backLabel}</Link>
            </Button>
            {target.editable && <Button size="sm" onClick={() => onSave(appState.data)} disabled={saving}>{saving ? 'Saving…' : 'Save email'}</Button>}
        </div>
    );
}

export default function EmailEditor({ campaign, design, events, saveUrl, backUrl, backLabel, kind = 'campaign' }: Props) {
    'use no memo';
    const designUrl = saveUrl ?? `/admin/edm/campaigns/${campaign.id}/design`;
    const target: Target = {
        saveUrl: designUrl,
        // Every design route has a preview beside it: …/design → …/preview.
        previewUrl: designUrl.replace(/\/design$/, '/preview'),
        backUrl: backUrl ?? `/admin/edm/campaigns/${campaign.id}`,
        backLabel: backLabel ?? 'Campaign',
        editable: campaign.editable,
    };
    const config = useMemo(() => emailConfig(events), [events]);
    const headerPath = kind === 'template' ? 'template' : (campaign.editable ? 'email design' : 'sent — read only');

    // Both versions are kept: switching to HTML and back never loses the blocks.
    const [mode, setMode] = useState<Mode>(design.editor === 'html' ? 'html' : 'builder');
    const [html, setHtml] = useState(design.html ?? '');
    const [blocks, setBlocks] = useState<Data>({ root: design.root, content: design.content } as Data);
    const [saved, setSaved] = useState(() => snapshot(blocks, mode, html));
    const [saving, setSaving] = useState(false);

    const dirty = snapshot(blocks, mode, html) !== saved;

    // Leaving with unsaved changes loses them; ask first.
    useUnsavedChanges(dirty && campaign.editable);

    const save = async (current: Data) => {
        setSaving(true);

        try {
            const res = await fetch(target.saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie('XSRF-TOKEN') ?? '') },
                credentials: 'same-origin',
                body: JSON.stringify({ design: { root: current.root, content: current.content, editor: mode, html } }),
            });

            if (!res.ok) {
                throw new Error(res.status === 422 ? 'invalid' : 'failed');
            }

            setBlocks(current);
            setSaved(snapshot(current, mode, html));
            toast.success('Email saved');
        } catch (error) {
            toast.error((error as Error).message === 'invalid'
                ? 'That email could not be saved — it may be too large.'
                : 'Save failed — please try again.');
        } finally {
            setSaving(false);
        }
    };

    const loadFile = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (file) {
            file.text().then(setHtml);
        }
    };

    if (mode === 'html') {
        return (
            <>
                <Head title={`Email · ${campaign.name}`} />
                <div className="flex h-screen flex-col bg-background text-foreground">
                    <header className="flex flex-wrap items-center gap-3 border-b border-border px-4 py-2">
                        <div className="min-w-0 flex-1 truncate text-sm font-semibold">
                            {campaign.name} <span className="font-normal text-muted-foreground">· {headerPath}</span>
                        </div>
                        <ModeSwitch mode={mode} onChange={setMode} />
                        <Button asChild variant="outline" size="sm">
                            <a href={target.previewUrl} target="_blank" rel="noopener" title="The last saved version, with the footer every email gets">
                                <ExternalLink className="size-4" /> Preview as sent
                            </a>
                        </Button>
                        <Button asChild variant="ghost" size="sm">
                            <Link href={target.backUrl}><ArrowLeft className="size-4" /> {target.backLabel}</Link>
                        </Button>
                        {target.editable && <Button size="sm" onClick={() => save(blocks)} disabled={saving}>{saving ? 'Saving…' : 'Save email'}</Button>}
                    </header>

                    <div className="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-2">
                        <div className="flex min-h-[50vh] flex-col border-b border-border lg:min-h-0 lg:border-r lg:border-b-0">
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-3 py-2">
                                <span className="flex items-center gap-2 text-sm font-medium"><Code2 className="size-4" /> Email HTML</span>
                                {target.editable && (
                                    <label className="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md border border-border px-3 text-xs font-medium hover:bg-accent">
                                        <Upload className="size-3.5" /> Load .html file
                                        <input type="file" accept=".html,.htm,text/html" className="sr-only" onChange={loadFile} />
                                    </label>
                                )}
                            </div>
                            <textarea
                                aria-label="Email HTML"
                                spellCheck={false}
                                readOnly={!target.editable}
                                value={html}
                                onChange={(e) => setHtml(e.target.value)}
                                placeholder={'<!DOCTYPE html>\n<html>\n<body>\n  <table width="600">…</table>\n</body>\n</html>'}
                                className="min-h-0 flex-1 resize-none bg-transparent p-4 font-mono text-xs leading-relaxed outline-none"
                            />
                            <p className="border-t border-border px-3 py-2 text-xs text-muted-foreground">
                                Merge tags such as {'{{first_name}}'} work. Every email gets the unsubscribe footer added automatically, and links are tracked.
                                Scripts, forms and embedded frames are removed — inboxes would block them anyway.
                            </p>
                        </div>
                        <div className="min-h-0 overflow-auto p-4">
                            <HtmlPreview html={html} height={720} devices={['desktop', 'phone']} empty="Paste your email’s HTML to see it here." />
                        </div>
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={`Email · ${campaign.name}`} />
            <div className="h-screen">
                <Puck
                    config={config}
                    data={blocks}
                    headerTitle={campaign.name}
                    headerPath={headerPath}
                    iframe={{ waitForStyles: false }}
                    // Email-sized, not the site's 1280px desktop: an email is a
                    // 600px column, and fitting a desktop viewport into the
                    // canvas shrank it to an unreadable third of its size.
                    viewports={[
                        { width: 640, height: 'auto', label: 'Email', icon: 'Monitor' },
                        { width: 375, height: 'auto', label: 'Phone', icon: 'Smartphone' },
                    ]}
                    onPublish={() => {}}
                    // Kept up to date so switching to HTML and back keeps the blocks.
                    onChange={setBlocks}
                    overrides={{
                        headerActions: () => (
                            <BuilderActions target={target} mode={mode} onMode={setMode} onSave={save} saving={saving} />
                        ),
                    }}
                />
            </div>
        </>
    );
}
