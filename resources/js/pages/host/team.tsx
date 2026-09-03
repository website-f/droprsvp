import { Head, router, useForm } from '@inertiajs/react';
import { Mail, ShieldCheck, UserPlus, Users, X } from 'lucide-react';
import { useConfirm } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

interface Member { id: number; name: string | null; email: string | null; role: string; added: string | null }

const field = 'h-11 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function Team({ members }: { members: Member[] }) {
    const confirm = useConfirm();
    const form = useForm({ email: '' });

    const add = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/host/team', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const remove = async (m: Member) => {
        if (await confirm({ title: `Remove ${m.name ?? 'this collaborator'}?`, description: 'They’ll immediately lose access to your events.', confirmText: 'Remove', destructive: true })) {
            router.delete(`/host/team/${m.id}`, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Team" />
            <div className="mx-auto w-full max-w-2xl flex-1 p-4">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight">Team</h1>
                    <p className="text-sm text-muted-foreground">Add collaborators to help manage your events. They can create and edit events, check people in, handle orders, refunds, promo codes and the waitlist — but not your payouts, team or account.</p>
                </div>

                <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <h2 className="text-sm font-semibold">Add a collaborator</h2>
                    <p className="mt-0.5 text-xs text-muted-foreground">Enter the email of someone with a DropRSVP account.</p>
                    <form onSubmit={add} className="mt-4 flex flex-wrap items-end gap-2">
                        <div className="grid min-w-56 flex-1 gap-1.5">
                            <Label htmlFor="email">Email</Label>
                            <input id="email" type="email" className={field} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} placeholder="teammate@email.com" />
                        </div>
                        <Button type="submit" disabled={form.processing || !form.data.email}><UserPlus className="size-4" /> Add</Button>
                    </form>
                    {form.errors.email && <p className="mt-2 text-xs text-destructive">{form.errors.email}</p>}
                </div>

                <div className="mt-6">
                    {members.length === 0 ? (
                        <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border py-14 text-center">
                            <span className="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"><Users className="size-5" /></span>
                            <p className="mt-3 text-sm font-medium">No collaborators yet</p>
                            <p className="mt-1 text-xs text-muted-foreground">Add a teammate above to share the work.</p>
                        </div>
                    ) : (
                        <ul className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                            {members.map((m) => (
                                <li key={m.id} className="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3 last:border-0">
                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-bold text-muted-foreground">{(m.name ?? '?').slice(0, 2).toUpperCase()}</span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="truncate text-sm font-semibold">{m.name}</span>
                                            <Badge variant="secondary"><ShieldCheck className="size-3" /> Manager</Badge>
                                        </div>
                                        <div className="flex items-center gap-1 truncate text-xs text-muted-foreground"><Mail className="size-3" /> {m.email} · added {m.added}</div>
                                    </div>
                                    <Button size="sm" variant="ghost" className="text-destructive hover:text-destructive" onClick={() => remove(m)}><X className="size-3.5" /> Remove</Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}

Team.layout = { breadcrumbs: [{ title: 'Team', href: '/host/team' }] };
