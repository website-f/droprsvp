import { Head, Link, router } from '@inertiajs/react';
import { Check, ClockArrowUp, Plus, Workflow } from 'lucide-react';
import { useState } from 'react';
import { automationStatus, TRIGGER_ICON, TRIGGER_TINT } from '@/components/edm/automation';
import { EDM_CRUMB, EdmHeader, pct } from '@/components/edm/ui';
import { Button } from '@/components/ui/button';

interface Automation { id: number; name: string; trigger: string; status: 'draft' | 'active' | 'paused'; steps: number; timeline: string[]; active: number; enrolled: number; sent: number; open_rate: number | null; click_rate: number | null }
interface Trigger { key: string; label: string; description: string; steps: string[]; exists: boolean }
interface Props {
    base?: string;
    automations: Automation[];
    triggers: Trigger[];
    lastRun: { at: string; enrolled: number; queued: number } | null;
}

export default function EdmAutomations({ base = '/admin/edm', automations, triggers, lastRun }: Props) {
    const [creating, setCreating] = useState('');

    const create = (key: string) => {
        setCreating(key);
        router.post(`${base}/automations`, { trigger: key }, { onFinish: () => setCreating('') });
    };

    return (
        <>
            <Head title="Automations" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <EdmHeader
                    title="Automations"
                    description="Emails that send themselves at the right moment — before an event, after it, when someone leaves checkout, or joins your list. Set one up once and it runs for every event."
                />

                {automations.length > 0 && (
                    <section className="mb-8 grid gap-3">
                        {automations.map((a) => {
                            const Icon = TRIGGER_ICON[a.trigger] ?? Workflow;
                            const tint = TRIGGER_TINT[a.trigger] ?? '#6c63ff';

                            return (
                                <Link key={a.id} href={`${base}/automations/${a.id}`} className="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4 shadow-sm transition-colors hover:border-foreground/30 md:flex-row md:items-center md:justify-between">
                                    <div className="flex min-w-0 items-start gap-3">
                                        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl" style={{ backgroundColor: `${tint}1f`, color: tint }}><Icon className="size-5" /></span>
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2"><span className="truncate font-semibold">{a.name}</span>{automationStatus(a.status)}</div>
                                            <div className="mt-1 flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                                                {a.timeline.map((t, i) => (
                                                    <span key={i} className="inline-flex items-center gap-1">
                                                        {i > 0 && <span className="text-muted-foreground/50">→</span>}
                                                        <span className="rounded-full bg-muted px-2 py-0.5">{t}</span>
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="grid shrink-0 grid-cols-4 gap-4 text-center text-xs md:text-right">
                                        <div><div className="font-semibold tabular-nums text-foreground">{a.active.toLocaleString()}</div><div className="text-muted-foreground">in progress</div></div>
                                        <div><div className="font-semibold tabular-nums text-foreground">{a.sent.toLocaleString()}</div><div className="text-muted-foreground">sent</div></div>
                                        <div><div className="font-semibold tabular-nums text-foreground">{pct(a.open_rate)}</div><div className="text-muted-foreground">opened</div></div>
                                        <div><div className="font-semibold tabular-nums text-foreground">{pct(a.click_rate)}</div><div className="text-muted-foreground">clicked</div></div>
                                    </div>
                                </Link>
                            );
                        })}
                    </section>
                )}

                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold"><Plus className="size-4" /> {automations.length ? 'Add another' : 'Start with a ready-made sequence'}</h2>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {triggers.map((t) => {
                        const Icon = TRIGGER_ICON[t.key] ?? Workflow;
                        const tint = TRIGGER_TINT[t.key] ?? '#6c63ff';

                        return (
                            <div key={t.key} className="flex flex-col rounded-2xl border border-border bg-card p-5 shadow-sm">
                                <div className="flex items-start gap-3">
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl" style={{ backgroundColor: `${tint}1f`, color: tint }}><Icon className="size-5" /></span>
                                    <div className="min-w-0">
                                        <div className="font-semibold">{t.label}</div>
                                        <p className="mt-0.5 text-xs text-muted-foreground">{t.description}</p>
                                    </div>
                                </div>
                                <div className="my-4 flex flex-wrap items-center gap-1 text-xs">
                                    {t.steps.map((s, i) => (
                                        <span key={i} className="inline-flex items-center gap-1">
                                            {i > 0 && <span className="text-muted-foreground/50">→</span>}
                                            <span className="inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5"><ClockArrowUp className="size-3" /> {s}</span>
                                        </span>
                                    ))}
                                </div>
                                <Button className="mt-auto" variant={t.exists ? 'outline' : 'default'} disabled={creating !== ''} onClick={() => create(t.key)}>
                                    {creating === t.key ? 'Setting up…' : t.exists ? <><Plus className="size-4" /> Another one</> : <><Check className="size-4" /> Use this sequence</>}
                                </Button>
                            </div>
                        );
                    })}
                </div>

                <p className="mt-4 text-xs text-muted-foreground">
                    Reminders, thank-yous and recovery emails are about something the person did, so they go to ticket holders without marketing consent — but anyone can unsubscribe from them. Promotional steps only reach people on your list.
                    {lastRun && ` Last run ${new Date(lastRun.at).toLocaleString()}.`}
                </p>
            </div>
        </>
    );
}

EdmAutomations.layout = { breadcrumbs: [EDM_CRUMB, { title: 'Automations', href: '/admin/edm/automations' }] };
