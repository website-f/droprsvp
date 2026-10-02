import { router } from '@inertiajs/react';
import { BadgeCheck, Loader2, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { PersonAvatar } from '@/components/chat/parts';
import type { Person } from '@/components/chat/parts';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { postJson } from '@/lib/api';

/** Start a conversation: search the people you can reach. */
export function NewChatDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
    const [q, setQ] = useState('');
    const [people, setPeople] = useState<Person[]>([]);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (q.trim().length < 2) {
            return;
        }

        const controller = new AbortController();
        const id = window.setTimeout(() => {
            setBusy(true);
            fetch(`/chat/search?q=${encodeURIComponent(q.trim())}`, { headers: { Accept: 'application/json' }, signal: controller.signal })
                .then((r) => r.json())
                .then((d) => setPeople(d.people ?? []))
                .catch(() => {})
                .finally(() => setBusy(false));
        }, 250);

        return () => {
            window.clearTimeout(id);
            controller.abort();
        };
    }, [q]);

    const shown = q.trim().length < 2 ? [] : people;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>New message</DialogTitle>
                    <DialogDescription>Organizers, people you follow or who follow you, and people you have sold tickets to.</DialogDescription>
                </DialogHeader>
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search by name…" className="h-11 w-full rounded-xl border border-input bg-card pl-9 pr-9 text-[16px] outline-none focus-visible:border-ring sm:text-sm" />
                    {busy && <Loader2 className="absolute right-3 top-1/2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />}
                </div>
                <div className="max-h-80 overflow-y-auto">
                    {q.trim().length >= 2 && !busy && shown.length === 0 && <p className="py-8 text-center text-sm text-muted-foreground">No one found.</p>}
                    {shown.map((p) => (
                        <button key={p.id} type="button" onClick={() => {
                            onOpenChange(false);
                            router.visit(`/messages/new/${p.id}`);
                        }} className="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left hover:bg-muted/60">
                            <PersonAvatar person={p} size={40} />
                            <span className="min-w-0 flex-1">
                                <span className="flex items-center gap-1 truncate text-sm font-medium">{p.name}{p.organizer && <BadgeCheck className="size-3.5 text-primary" />}</span>
                                <span className="text-xs text-muted-foreground">{p.organizer ? 'Organizer' : p.online ? 'Online' : ''}</span>
                            </span>
                        </button>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}

/** Report someone (optionally one message), with the option to block at the same time. */
export function ReportDialog({ target, reasons, onOpenChange, onDone }: {
    target: { userId: number; name: string; conversationId?: number; messageId?: number } | null;
    reasons: { value: string; label: string }[];
    onOpenChange: (o: boolean) => void;
    onDone: (blocked: boolean) => void;
}) {
    const [reason, setReason] = useState('spam');
    const [details, setDetails] = useState('');
    const [block, setBlock] = useState(true);
    const [busy, setBusy] = useState(false);

    const submit = async () => {
        if (!target) {
            return;
        }

        setBusy(true);

        try {
            await postJson('/chat/reports', { user_id: target.userId, conversation_id: target.conversationId, message_id: target.messageId, reason, details, block });
            toast.success('Thanks — our team will review it.');
            onDone(block);
            onOpenChange(false);
        } catch {
            toast.error('Could not send the report. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={target !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Report {target?.name}</DialogTitle>
                    <DialogDescription>Reports are private. {target?.name} won’t know who reported them.</DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    {reasons.map((r) => (
                        <label key={r.value} className={`flex cursor-pointer items-center gap-3 rounded-xl border p-3 text-sm ${reason === r.value ? 'border-primary bg-primary/5' : 'border-border'}`}>
                            <input type="radio" name="reason" checked={reason === r.value} onChange={() => setReason(r.value)} />
                            {r.label}
                        </label>
                    ))}
                    <textarea value={details} onChange={(e) => setDetails(e.target.value)} rows={3} maxLength={1000} placeholder="Anything that would help us (optional)" className="w-full rounded-xl border border-input bg-card px-3 py-2 text-[16px] outline-none focus-visible:border-ring sm:text-sm" />
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={block} onChange={(e) => setBlock(e.target.checked)} /> Also block {target?.name}</label>
                </div>
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button variant="destructive" onClick={submit} disabled={busy}>Send report</Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
