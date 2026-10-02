import { Head, Link, router, useForm } from '@inertiajs/react';
import { Eye, Flag, Mail, MoreHorizontal, Pause, Pencil, Play, Plus, Send, Trash2, Workflow } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { automationStatus, TRIGGER_ICON, TRIGGER_TINT } from '@/components/edm/automation';
import { EDM_CRUMB, EdmHeader, field, pct, Section, StatusPill } from '@/components/edm/ui';
import { ResponsiveTable } from '@/components/responsive-table';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SearchableSelect } from '@/components/ui/searchable-select';
import type { SearchableOption } from '@/components/ui/searchable-select';
import { Switch } from '@/components/ui/switch';

interface Step {
    id: number; position: number; timing: string; delay_value: number; delay_unit: 'minutes' | 'hours' | 'days'; delay_direction: 'before' | 'after';
    conditions: string[]; subject: string; preheader: string | null; marketing: boolean;
    stats: { queued: number; sent: number; opened: number; clicked: number; bounced: number; unsubscribed: number; skipped: number; open_rate: number | null; click_rate: number | null };
}
interface Props {
    base?: string;
    automation: { id: number; name: string; trigger: string; trigger_label: string; trigger_help: string; anchor: string; status: 'draft' | 'active' | 'paused'; event_ids: number[]; activated_at: string | null };
    steps: Step[];
    enrollmentCounts: { active: number; completed: number; exited: number };
    enrollments: { id: number; email: string; name: string | null; status: string; step: number; next: string | null; reason: string | null; when: string | null }[];
    conditions: { value: string; label: string }[];
    tokens: string[];
    events: SearchableOption[];
    testEmail: string;
}

/** One email in the sequence: its timing, conditions, content and how it is doing. */
function StepCard({ step, index, automation, base, conditions, onEdit, onPreview, onTest, onDelete }: {
    step: Step; index: number; automation: Props['automation']; base: string; conditions: Props['conditions'];
    onEdit: () => void; onPreview: () => void; onTest: () => void; onDelete: () => void;
}) {
    const label = (key: string) => conditions.find((c) => c.value === key)?.label ?? key;

    return (
        <li className="relative pl-10 sm:pl-12">
            <span className="absolute left-0 top-4 flex size-8 items-center justify-center rounded-full border-2 border-primary bg-card text-xs font-bold text-primary sm:size-9">{index + 1}</span>
            <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                        <div className="mb-1 inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary">
                            {step.timing}{step.delay_value > 0 && ` ${automation.anchor}`}
                        </div>
                        <div className="truncate font-semibold">{step.subject || <span className="text-muted-foreground">No subject yet</span>}</div>
                        {step.preheader && <div className="truncate text-xs text-muted-foreground">{step.preheader}</div>}
                    </div>
                    <div className="flex items-center gap-1">
                        <Button size="sm" variant="outline" onClick={onEdit}><Pencil className="size-3.5" /> Edit</Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild><Button size="icon" variant="ghost" className="size-8" aria-label="More"><MoreHorizontal className="size-4" /></Button></DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem onClick={() => router.visit(`${base}/automations/${automation.id}/steps/${step.id}/editor`)}><Mail className="size-4" /> Design email</DropdownMenuItem>
                                <DropdownMenuItem onClick={onPreview}><Eye className="size-4" /> Preview</DropdownMenuItem>
                                <DropdownMenuItem onClick={onTest}><Send className="size-4" /> Send a test</DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem className="text-destructive" onClick={onDelete}><Trash2 className="size-4" /> Remove email</DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                {(step.conditions.length > 0 || step.marketing) && (
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {step.marketing && <span className="rounded-full bg-amber-500/10 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:text-amber-400">Promotional · subscribers only</span>}
                        {step.conditions.filter((c) => !(c === 'subscribed' && step.marketing)).map((c) => (
                            <span key={c} className="rounded-full border border-border px-2 py-0.5 text-[11px] text-muted-foreground">Only if: {label(c).split(' (')[0].toLowerCase()}</span>
                        ))}
                    </div>
                )}

                {/* Per-step performance */}
                <div className="mt-3 grid grid-cols-3 gap-2 border-t border-border pt-3 text-center sm:grid-cols-6">
                    {[
                        ['Sent', step.stats.sent.toLocaleString()],
                        ['Opened', pct(step.stats.open_rate)],
                        ['Clicked', pct(step.stats.click_rate)],
                        ['Skipped', step.stats.skipped.toLocaleString()],
                        ['Bounced', step.stats.bounced.toLocaleString()],
                        ['Unsubscribed', step.stats.unsubscribed.toLocaleString()],
                    ].map(([k, v]) => (
                        <div key={k}><div className="text-sm font-semibold tabular-nums">{v}</div><div className="text-[10px] text-muted-foreground">{k}</div></div>
                    ))}
                </div>
            </div>
        </li>
    );
}

export default function EdmAutomation({ base = '/admin/edm', automation, steps, enrollmentCounts, enrollments, conditions, tokens, events, testEmail }: Props) {
    const confirm = useConfirm();
    const Icon = TRIGGER_ICON[automation.trigger] ?? Workflow;
    const tint = TRIGGER_TINT[automation.trigger] ?? '#6c63ff';
    const url = `${base}/automations/${automation.id}`;

    const settings = useForm({ name: automation.name, event_ids: automation.event_ids });
    const [editing, setEditing] = useState<Step | null>(null);
    const stepForm = useForm({ delay_value: 1, delay_unit: 'days' as Step['delay_unit'], delay_direction: 'after' as Step['delay_direction'], conditions: [] as string[], subject: '', preheader: '', marketing: false });
    const [preview, setPreview] = useState<Step | null>(null);
    const [testing, setTesting] = useState<Step | null>(null);
    const testForm = useForm({ emails: testEmail });

    const openEdit = (s: Step) => {
        stepForm.setData({ delay_value: s.delay_value, delay_unit: s.delay_unit, delay_direction: s.delay_direction, conditions: s.conditions, subject: s.subject, preheader: s.preheader ?? '', marketing: s.marketing });
        stepForm.clearErrors();
        setEditing(s);
    };

    const toggleCondition = (c: string) => stepForm.setData('conditions', stepForm.data.conditions.includes(c) ? stepForm.data.conditions.filter((x) => x !== c) : [...stepForm.data.conditions, c]);

    const removeStep = async (s: Step) => {
        if (await confirm({ title: 'Remove this email?', description: 'People waiting for it skip to the next one. Its statistics stay on record.', confirmText: 'Remove', destructive: true })) {
            router.delete(`${url}/steps/${s.id}`, { preserveScroll: true });
        }
    };

    const remove = async () => {
        if (await confirm({ title: `Delete “${automation.name}”?`, description: 'It stops at once. Emails already sent stay sent.', confirmText: 'Delete', destructive: true })) {
            router.delete(url);
        }
    };

    const eventTriggers = ['event_reminder', 'post_event', 'abandoned_checkout'].includes(automation.trigger);

    return (
        <>
            <Head title={automation.name} />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <EdmHeader
                    back={{ href: `${base}/automations`, label: 'Automations' }}
                    title={<span className="flex flex-wrap items-center gap-2">{automation.name} {automationStatus(automation.status)}</span>}
                    actions={(
                        <>
                            {automation.status === 'active'
                                ? <Button variant="outline" onClick={() => router.post(`${url}/pause`, {}, { preserveScroll: true })}><Pause className="size-4" /> Pause</Button>
                                : <Button onClick={() => router.post(`${url}/activate`, {}, { preserveScroll: true })}><Play className="size-4" /> Switch on</Button>}
                            <Button variant="ghost" onClick={remove} aria-label="Delete sequence"><Trash2 className="size-4" /></Button>
                        </>
                    )}
                />

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
                    <div className="min-w-0">
                        {/* The trigger, as the top of the timeline. */}
                        <div className="relative pl-10 sm:pl-12">
                            <span className="absolute left-0 top-4 flex size-8 items-center justify-center rounded-full sm:size-9" style={{ backgroundColor: `${tint}1f`, color: tint }}><Flag className="size-4" /></span>
                            <div className="rounded-2xl border border-dashed border-border bg-muted/30 p-4">
                                <div className="flex items-center gap-2 text-sm font-semibold"><Icon className="size-4" style={{ color: tint }} /> Trigger: {automation.trigger_label}</div>
                                <p className="mt-1 text-xs text-muted-foreground">{automation.trigger_help}</p>
                            </div>
                        </div>

                        <ol className="relative mt-3 grid gap-3 before:absolute before:bottom-6 before:left-4 before:top-0 before:w-px before:bg-border sm:before:left-[18px]">
                            {steps.map((s, i) => (
                                <StepCard
                                    key={s.id}
                                    step={s}
                                    index={i}
                                    automation={automation}
                                    base={base}
                                    conditions={conditions}
                                    onEdit={() => openEdit(s)}
                                    onPreview={() => setPreview(s)}
                                    onTest={() => {
                                        testForm.clearErrors();
                                        setTesting(s);
                                    }}
                                    onDelete={() => removeStep(s)}
                                />
                            ))}
                        </ol>

                        <div className="mt-3 pl-10 sm:pl-12">
                            <Button variant="outline" className="w-full border-dashed" onClick={() => router.post(`${url}/steps`, {}, { preserveScroll: true })}><Plus className="size-4" /> Add an email</Button>
                        </div>
                    </div>

                    <aside className="grid content-start gap-4">
                        <Section title="People in this sequence">
                            <div className="grid grid-cols-3 gap-2 text-center">
                                <div><div className="text-xl font-bold tabular-nums">{enrollmentCounts.active.toLocaleString()}</div><div className="text-[11px] text-muted-foreground">in progress</div></div>
                                <div><div className="text-xl font-bold tabular-nums">{enrollmentCounts.completed.toLocaleString()}</div><div className="text-[11px] text-muted-foreground">finished</div></div>
                                <div><div className="text-xl font-bold tabular-nums">{enrollmentCounts.exited.toLocaleString()}</div><div className="text-[11px] text-muted-foreground">left early</div></div>
                            </div>
                            {automation.activated_at && <p className="mt-3 text-[11px] text-muted-foreground">Running since {automation.activated_at}.</p>}
                        </Section>

                        <Section title="Settings">
                            <form onSubmit={(e) => {
                                e.preventDefault();
                                settings.put(url, { preserveScroll: true });
                            }} className="grid gap-3">
                                <label className="grid gap-1.5 text-sm">
                                    <span className="font-medium">Name</span>
                                    <input className={field} value={settings.data.name} onChange={(e) => settings.setData('name', e.target.value)} />
                                </label>
                                {eventTriggers && (
                                    <div className="grid gap-1.5 text-sm">
                                        <span className="font-medium">Events</span>
                                        <SearchableSelect
                                            value=""
                                            onChange={(v) => v && !settings.data.event_ids.includes(Number(v)) && settings.setData('event_ids', [...settings.data.event_ids, Number(v)])}
                                            options={events}
                                            placeholder={settings.data.event_ids.length ? 'Add another event…' : 'All events'}
                                            searchPlaceholder="Search events…"
                                        />
                                        {settings.data.event_ids.length > 0 && (
                                            <div className="flex flex-wrap gap-1.5">
                                                {settings.data.event_ids.map((id) => (
                                                    <button key={id} type="button" onClick={() => settings.setData('event_ids', settings.data.event_ids.filter((x) => x !== id))} className="rounded-full border border-foreground bg-foreground px-2.5 py-0.5 text-[11px] text-background">
                                                        {events.find((e) => Number(e.value) === id)?.label ?? `#${id}`} ×
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                        <span className="text-[11px] text-muted-foreground">Leave empty to cover every event{base.startsWith('/host') ? ' you run' : ''}.</span>
                                    </div>
                                )}
                                <div><Button type="submit" size="sm" disabled={settings.processing || !settings.isDirty}>Save</Button></div>
                            </form>
                        </Section>

                        <Section title="Personalise" description="Use these in subjects and emails; each person gets their own event’s details.">
                            <div className="flex flex-wrap gap-1.5">
                                {['first_name', ...tokens].map((t) => (
                                    <code key={t} className="rounded bg-muted px-1.5 py-0.5 text-[11px]">{`{{${t}}}`}</code>
                                ))}
                            </div>
                        </Section>
                    </aside>
                </div>

                <Section className="mt-6" padded={false} title="Recent people" description="The last 25 to enter this sequence.">
                    <ResponsiveTable>
                        <table className="w-full min-w-[640px] text-sm">
                            <thead className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr className="border-b border-border"><th className="px-4 py-2 font-medium">Person</th><th className="px-4 py-2 font-medium">Status</th><th className="px-4 py-2 font-medium">Next</th><th className="px-4 py-2 font-medium">Joined</th></tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {enrollments.length === 0 ? (
                                    <tr><td colSpan={4} className="px-4 py-10 text-center text-muted-foreground">{automation.status === 'active' ? 'Nobody yet — people join as the trigger happens.' : 'Switch the sequence on to start.'}</td></tr>
                                ) : enrollments.map((e) => (
                                    <tr key={e.id}>
                                        <td className="px-4 py-2"><div className="font-medium">{e.name || e.email}</div>{e.name && <div className="text-xs text-muted-foreground">{e.email}</div>}</td>
                                        <td className="px-4 py-2"><StatusPill status={e.status === 'active' ? 'pass' : e.status === 'completed' ? 'good' : 'unknown'} label={e.status === 'active' ? `At email ${e.step}` : e.status === 'completed' ? 'Finished' : 'Left'} />{e.reason && <div className="mt-1 text-[11px] text-muted-foreground">{e.reason}</div>}</td>
                                        <td className="whitespace-nowrap px-4 py-2 text-xs text-muted-foreground">{e.next ?? '—'}</td>
                                        <td className="whitespace-nowrap px-4 py-2 text-xs text-muted-foreground">{e.when}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </ResponsiveTable>
                </Section>
            </div>

            {/* Edit a step */}
            <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit email</DialogTitle>
                        <DialogDescription>When it goes, who it goes to, and its subject. Design the body with “Design email”.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={(e) => {
                        e.preventDefault();
                        stepForm.put(`${url}/steps/${editing?.id}`, { preserveScroll: true, onSuccess: () => setEditing(null) });
                    }} className="grid gap-4">
                        <div className="grid gap-1.5 text-sm">
                            <span className="font-medium">Send</span>
                            <div className="grid grid-cols-[5rem_minmax(0,1fr)] gap-2 sm:grid-cols-[5rem_minmax(0,1fr)_minmax(0,1fr)]">
                                <input type="number" min={0} className={field} value={stepForm.data.delay_value} onChange={(e) => stepForm.setData('delay_value', Number(e.target.value))} />
                                <select className={field} value={stepForm.data.delay_unit} onChange={(e) => stepForm.setData('delay_unit', e.target.value as Step['delay_unit'])}>
                                    <option value="minutes">minutes</option><option value="hours">hours</option><option value="days">days</option>
                                </select>
                                <select className={`${field} col-span-2 sm:col-span-1`} value={stepForm.data.delay_direction} onChange={(e) => stepForm.setData('delay_direction', e.target.value as Step['delay_direction'])} disabled={automation.trigger !== 'event_reminder'}>
                                    {automation.trigger === 'event_reminder' && <option value="before">before {automation.anchor}</option>}
                                    <option value="after">after {automation.anchor}</option>
                                </select>
                            </div>
                            {stepForm.errors.delay_direction && <span className="text-xs text-destructive">{stepForm.errors.delay_direction}</span>}
                        </div>

                        <div className="grid gap-1.5 text-sm">
                            <span className="font-medium">Only send if… <span className="font-normal text-muted-foreground">(all that are ticked)</span></span>
                            <div className="grid gap-1.5">
                                {conditions.map((c) => (
                                    <label key={c.value} className="flex items-start gap-2 text-xs">
                                        <input type="checkbox" className="mt-0.5" checked={stepForm.data.conditions.includes(c.value)} onChange={() => toggleCondition(c.value)} />
                                        <span>{c.label}</span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        <label className="grid gap-1.5 text-sm">
                            <span className="font-medium">Subject line</span>
                            <input className={field} value={stepForm.data.subject} onChange={(e) => stepForm.setData('subject', e.target.value)} />
                            {stepForm.errors.subject && <span className="text-xs text-destructive">{stepForm.errors.subject}</span>}
                        </label>
                        <label className="grid gap-1.5 text-sm">
                            <span className="font-medium">Preview text</span>
                            <input className={field} value={stepForm.data.preheader} onChange={(e) => stepForm.setData('preheader', e.target.value)} />
                        </label>
                        <label className="flex items-start justify-between gap-3 rounded-xl border border-border p-3 text-sm">
                            <span>
                                <span className="block font-medium">Promotional</span>
                                <span className="block text-xs text-muted-foreground">Promotes other events or offers. Then it only goes to people on your list, as the law requires.</span>
                            </span>
                            <Switch checked={stepForm.data.marketing} onCheckedChange={(v) => stepForm.setData('marketing', v)} aria-label="Promotional" />
                        </label>

                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>Cancel</Button>
                            <Button type="submit" disabled={stepForm.processing}>Save email</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Preview */}
            <Dialog open={preview !== null} onOpenChange={(o) => !o && setPreview(null)}>
                <DialogContent className="flex h-[90vh] max-w-3xl flex-col gap-3 p-4 sm:p-6">
                    <DialogHeader>
                        <DialogTitle>{preview?.subject}</DialogTitle>
                        <DialogDescription>With sample event details. <Link href={`${url}/steps/${preview?.id}/editor`} className="font-medium text-foreground underline">Design email</Link></DialogDescription>
                    </DialogHeader>
                    {preview && <iframe src={`${url}/steps/${preview.id}/preview`} title="Preview" sandbox="" className="min-h-0 w-full flex-1 rounded-xl border border-border bg-white" />}
                </DialogContent>
            </Dialog>

            {/* Test */}
            <Dialog open={testing !== null} onOpenChange={(o) => !o && setTesting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Send a test</DialogTitle>
                        <DialogDescription>Up to five addresses, with sample event details filled in.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={(e) => {
                        e.preventDefault();
                        testForm.post(`${url}/steps/${testing?.id}/test`, { preserveScroll: true, onSuccess: () => setTesting(null) });
                    }} className="grid gap-3">
                        <input className={field} value={testForm.data.emails} onChange={(e) => testForm.setData('emails', e.target.value)} />
                        {testForm.errors.emails && <span className="text-xs text-destructive">{testForm.errors.emails}</span>}
                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Button type="button" variant="ghost" onClick={() => setTesting(null)}>Cancel</Button>
                            <Button type="submit" disabled={testForm.processing}><Send className="size-4" /> Send test</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

EdmAutomation.layout = { breadcrumbs: [EDM_CRUMB, { title: 'Automations', href: '/admin/edm/automations' }, { title: 'Sequence', href: '#' }] };
