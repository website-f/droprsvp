import { router } from '@inertiajs/react';
import { Check, FileText, LayoutTemplate, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { field } from '@/components/edm/ui';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

export interface StarterChoice { key: string; name: string; description: string }
export interface TemplateChoice { id: number; name: string; description: string | null }

type Pick = { kind: 'blank' } | { kind: 'starter'; key: string } | { kind: 'template'; id: number };

function Option({ selected, onClick, icon: Icon, title, description }: { selected: boolean; onClick: () => void; icon: typeof FileText; title: string; description?: string | null }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`flex w-full items-start gap-3 rounded-xl border p-3 text-left transition-colors ${selected ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border hover:border-foreground/30'}`}
        >
            <span className={`flex size-8 shrink-0 items-center justify-center rounded-lg ${selected ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'}`}>
                {selected ? <Check className="size-4" /> : <Icon className="size-4" />}
            </span>
            <span className="min-w-0">
                <span className="block text-sm font-medium">{title}</span>
                {description && <span className="block text-xs text-muted-foreground">{description}</span>}
            </span>
        </button>
    );
}

/**
 * "New campaign": a name, and where to start — blank, a built-in starter, or a
 * saved template. Starting from a design is the quickest way to a good first
 * email, so the starters are shown, not hidden behind a menu.
 */
export function NewCampaignDialog({ open, onOpenChange, starters, templates, basePath = '/admin/edm' }: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    starters: StarterChoice[];
    templates: TemplateChoice[];
    basePath?: string;
}) {
    const [name, setName] = useState('');
    const [pick, setPick] = useState<Pick>({ kind: 'blank' });
    const [busy, setBusy] = useState(false);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);
        const done = { onFinish: () => setBusy(false) };

        if (pick.kind === 'blank') {
            router.post(`${basePath}/campaigns`, { name }, done);
        } else if (pick.kind === 'starter') {
            router.post(`${basePath}/templates/use`, { starter: pick.key, name }, done);
        } else {
            router.post(`${basePath}/templates/use`, { template_id: pick.id, name }, done);
        }
    };


    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>New campaign</DialogTitle>
                    <DialogDescription>Name it, then pick where to start. You can change everything afterwards.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <label className="grid gap-1.5 text-sm">
                        <span className="font-medium">Name <span className="font-normal text-muted-foreground">(only you see this)</span></span>
                        <input autoFocus value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. October line-up" className={field} />
                    </label>

                    <div className="grid gap-2">
                        <div className="text-xs font-medium text-muted-foreground">Start from</div>
                        <Option selected={pick.kind === 'blank'} onClick={() => setPick({ kind: 'blank' })} icon={FileText} title="A simple starter" description="A heading, a paragraph and a button." />
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            {starters.map((s) => (
                                <Option key={s.key} selected={pick.kind === 'starter' && pick.key === s.key} onClick={() => setPick({ kind: 'starter', key: s.key })} icon={Sparkles} title={s.name} description={s.description} />
                            ))}
                        </div>
                        {templates.length > 0 && (
                            <>
                                <div className="mt-2 text-xs font-medium text-muted-foreground">Your templates</div>
                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    {templates.map((t) => (
                                        <Option key={t.id} selected={pick.kind === 'template' && pick.id === t.id} onClick={() => setPick({ kind: 'template', id: t.id })} icon={LayoutTemplate} title={t.name} description={t.description} />
                                    ))}
                                </div>
                            </>
                        )}
                    </div>

                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={busy || !name.trim()}>{busy ? 'Creating…' : 'Create campaign'}</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
