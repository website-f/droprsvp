import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { uploadImage } from '@/lib/upload';
import { Plus, Trash2, Upload } from 'lucide-react';

interface Sections {
    organizer: { enabled: boolean; heading: string; body: string; cta_label: string; cta_url: string; image: string };
    event_time: { enabled: boolean; heading: string; items: { label: string; value: string }[] };
    nearby_cities: { enabled: boolean; heading: string; cities: string[] };
}

const field = 'h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area = 'w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

function Toggle({ on, onChange, label }: { on: boolean; onChange: (v: boolean) => void; label: string }) {
    return (
        <label className="flex items-center gap-2 text-sm font-medium">
            <button type="button" role="switch" aria-checked={on} onClick={() => onChange(!on)}
                className={`relative h-6 w-11 rounded-full transition-colors ${on ? 'bg-foreground' : 'bg-input'}`}>
                <span className={`absolute top-0.5 size-5 rounded-full bg-background transition-transform ${on ? 'translate-x-[22px]' : 'translate-x-0.5'}`} />
            </button>
            {label}
        </label>
    );
}

function Card({ children }: { children: React.ReactNode }) {
    return (
        <section className="rounded-xl border border-border bg-card p-5 shadow-sm">
            <div className="grid gap-4">{children}</div>
        </section>
    );
}

export default function LandingSettings({ sections }: { sections: Sections }) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const form = useForm<Sections>(sections);
    const { data, setData, processing } = form;
    const [uploading, setUploading] = useState(false);

    const patch = <K extends keyof Sections>(key: K, val: Partial<Sections[K]>) => setData({ ...data, [key]: { ...data[key], ...val } });

    const uploadOrg = async (file?: File) => {
        if (!file) return;
        setUploading(true);
        try { patch('organizer', { image: await uploadImage(file) }); } catch { /* keep */ } finally { setUploading(false); }
    };

    const save = () => form.post('/admin/site/landing', { preserveScroll: true });

    return (
        <>
            <Head title="Landing sections" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Landing sections</h1>
                        <p className="text-sm text-muted-foreground">Toggle and edit the optional sections on your home page.</p>
                    </div>
                    <Button onClick={save} disabled={processing}>Save changes</Button>
                </div>
                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}

                <div className="grid gap-6">
                    {/* Organizer */}
                    <Card>
                        <div className="flex items-center justify-between">
                            <h2 className="font-semibold">Organizer promo</h2>
                            <Toggle on={data.organizer.enabled} onChange={(v) => patch('organizer', { enabled: v })} label={data.organizer.enabled ? 'Shown' : 'Hidden'} />
                        </div>
                        <div className="grid gap-1.5"><Label>Heading</Label><input className={field} value={data.organizer.heading} onChange={(e) => patch('organizer', { heading: e.target.value })} /></div>
                        <div className="grid gap-1.5"><Label>Body</Label><textarea rows={2} className={area} value={data.organizer.body} onChange={(e) => patch('organizer', { body: e.target.value })} /></div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5"><Label>Button label</Label><input className={field} value={data.organizer.cta_label} onChange={(e) => patch('organizer', { cta_label: e.target.value })} /></div>
                            <div className="grid gap-1.5"><Label>Button link</Label><input className={field} value={data.organizer.cta_url} onChange={(e) => patch('organizer', { cta_url: e.target.value })} /></div>
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Image (optional)</Label>
                            <div className="flex items-center gap-3">
                                {data.organizer.image && <img src={data.organizer.image} alt="" className="h-16 w-24 rounded-lg border border-border object-cover" />}
                                <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-input px-3 py-2 text-sm hover:bg-accent">
                                    <Upload className="size-4" />{uploading ? 'Uploading…' : 'Upload'}
                                    <input type="file" accept="image/*" hidden onChange={(e) => uploadOrg(e.target.files?.[0])} />
                                </label>
                                {data.organizer.image && <Button type="button" variant="ghost" size="sm" onClick={() => patch('organizer', { image: '' })}>Remove</Button>}
                            </div>
                        </div>
                    </Card>

                    {/* Event time */}
                    <Card>
                        <div className="flex items-center justify-between">
                            <h2 className="font-semibold">Event-time chips</h2>
                            <Toggle on={data.event_time.enabled} onChange={(v) => patch('event_time', { enabled: v })} label={data.event_time.enabled ? 'Shown' : 'Hidden'} />
                        </div>
                        <div className="grid gap-1.5"><Label>Heading</Label><input className={field} value={data.event_time.heading} onChange={(e) => patch('event_time', { heading: e.target.value })} /></div>
                        <div className="grid gap-2">
                            <Label>Chips (label + when filter)</Label>
                            {data.event_time.items.map((it, i) => (
                                <div key={i} className="flex gap-2">
                                    <input className={field} value={it.label} placeholder="Label e.g. This weekend" onChange={(e) => { const items = [...data.event_time.items]; items[i] = { ...it, label: e.target.value }; patch('event_time', { items }); }} />
                                    <select className={field + ' max-w-[160px]'} value={it.value} onChange={(e) => { const items = [...data.event_time.items]; items[i] = { ...it, value: e.target.value }; patch('event_time', { items }); }}>
                                        {['today', 'weekend', 'week', 'month'].map((v) => <option key={v} value={v}>{v}</option>)}
                                    </select>
                                    <button type="button" onClick={() => patch('event_time', { items: data.event_time.items.filter((_, j) => j !== i) })} className="flex size-10 shrink-0 items-center justify-center rounded-lg text-destructive hover:bg-destructive/10"><Trash2 className="size-4" /></button>
                                </div>
                            ))}
                            <Button type="button" variant="outline" size="sm" className="w-max" onClick={() => patch('event_time', { items: [...data.event_time.items, { label: '', value: 'today' }] })}><Plus className="size-4" /> Add chip</Button>
                        </div>
                    </Card>

                    {/* Nearby cities */}
                    <Card>
                        <div className="flex items-center justify-between">
                            <h2 className="font-semibold">Nearby cities</h2>
                            <Toggle on={data.nearby_cities.enabled} onChange={(v) => patch('nearby_cities', { enabled: v })} label={data.nearby_cities.enabled ? 'Shown' : 'Hidden'} />
                        </div>
                        <div className="grid gap-1.5"><Label>Heading</Label><input className={field} value={data.nearby_cities.heading} onChange={(e) => patch('nearby_cities', { heading: e.target.value })} /></div>
                        <div className="grid gap-1.5">
                            <Label>Cities (one per line)</Label>
                            <textarea rows={5} className={area} value={data.nearby_cities.cities.join('\n')} onChange={(e) => patch('nearby_cities', { cities: e.target.value.split('\n').map((s) => s.trim()).filter(Boolean) })} />
                        </div>
                    </Card>
                </div>

                <div className="mt-6 flex justify-end"><Button onClick={save} disabled={processing}>Save changes</Button></div>
            </div>
        </>
    );
}

LandingSettings.layout = { breadcrumbs: [{ title: 'Landing', href: '/admin/site/landing' }] };
