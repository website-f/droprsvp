import { Head, router, useForm } from '@inertiajs/react';
import { AtSign, Check, ClipboardCopy, Globe, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { EdmHeader, field, HOST_EDM_CRUMB, Section, StatusPill } from '@/components/edm/ui';
import { Button } from '@/components/ui/button';

interface DnsRecord { key: string; type: string; host: string; value: string; purpose: string; required: boolean }
interface Domain { id: number; domain: string; status: 'pending' | 'verified' | 'failed'; checks: Record<string, string>; records: DnsRecord[]; checked: string | null; verified: string | null }
interface Props { domains: Domain[]; platformFrom: string }

function Copy({ value }: { value: string }) {
    const [done, setDone] = useState(false);

    return (
        <button
            type="button"
            className="shrink-0 rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
            aria-label="Copy"
            onClick={() => void navigator.clipboard?.writeText(value).then(() => {
                setDone(true);
                window.setTimeout(() => setDone(false), 1500);
            })}
        >
            {done ? <Check className="size-3.5" /> : <ClipboardCopy className="size-3.5" />}
        </button>
    );
}

const CHECK: Record<string, { status: string; label: string }> = {
    pass: { status: 'pass', label: 'Found' },
    missing: { status: 'warn', label: 'Not found' },
    mismatch: { status: 'fail', label: 'Wrong value' },
};

export default function HostEdmDomains({ domains, platformFrom }: Props) {
    const confirm = useConfirm();
    const form = useForm({ domain: '' });
    const [checking, setChecking] = useState<number | null>(null);

    const verify = (d: Domain) => {
        setChecking(d.id);
        router.post(`/host/edm/domains/${d.id}/verify`, {}, { preserveScroll: true, onFinish: () => setChecking(null) });
    };

    const remove = async (d: Domain) => {
        if (await confirm({ title: `Remove ${d.domain}?`, description: 'Campaigns not yet sent from it switch back to the DropRSVP address.', confirmText: 'Remove', destructive: true })) {
            router.delete(`/host/edm/domains/${d.id}`, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Sending domain" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <EdmHeader
                    title="Sending domain"
                    description={<>Send as <span className="font-medium text-foreground">you@yourdomain.com</span> instead of {platformFrom}. Add two DNS records to prove the domain is yours; we then sign your mail with its own key, so inboxes trust it.</>}
                />

                <Section title={<span className="flex items-center gap-2"><Globe className="size-4" /> Add a domain</span>} description="Your own domain, or a sub-domain of it like mail.yourband.com (recommended: it keeps campaign mail separate from your everyday email).">
                    <form onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/host/edm/domains', { preserveScroll: true, onSuccess: () => form.reset() });
                    }} className="flex flex-col gap-2 sm:flex-row">
                        <input className={field} value={form.data.domain} onChange={(e) => form.setData('domain', e.target.value)} placeholder="mail.yourband.com" autoCapitalize="none" autoCorrect="off" spellCheck={false} />
                        <Button type="submit" disabled={form.processing || !form.data.domain.trim()} className="shrink-0">Add domain</Button>
                    </form>
                    {form.errors.domain && <p className="mt-2 text-xs text-destructive">{form.errors.domain}</p>}
                </Section>

                {domains.length === 0 && (
                    <div className="mt-4 flex flex-col items-center gap-2 rounded-2xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground">
                        <AtSign className="size-6" />
                        No domain yet. Until you add one, your campaigns go out from {platformFrom} with your name on them — that works fine.
                    </div>
                )}

                {domains.map((d) => (
                    <Section
                        key={d.id}
                        className="mt-4"
                        title={<span className="flex flex-wrap items-center gap-2">{d.domain} <StatusPill status={d.status === 'verified' ? 'pass' : d.status === 'failed' ? 'fail' : 'warn'} label={d.status === 'verified' ? 'Verified' : d.status === 'failed' ? 'Records missing' : 'Waiting for DNS'} /></span>}
                        description={d.status === 'verified'
                            ? `Verified${d.verified ? ` ${d.verified}` : ''}. Pick it as the From address on any campaign. Rechecked daily — keep the records in place.`
                            : d.status === 'failed'
                                ? 'This was verified, but the records are gone. Campaigns are sending from the DropRSVP address until they are back.'
                                : 'Publish the two required records at your domain’s DNS host (where you bought the domain, or Cloudflare), then press Verify. Changes can take up to an hour.'}
                        actions={(
                            <>
                                <Button size="sm" variant="outline" onClick={() => verify(d)} disabled={checking !== null}><RefreshCw className={`size-3.5 ${checking === d.id ? 'animate-spin' : ''}`} /> Verify</Button>
                                <Button size="sm" variant="ghost" onClick={() => remove(d)} aria-label={`Remove ${d.domain}`}><Trash2 className="size-4" /></Button>
                            </>
                        )}
                    >
                        <div className="grid gap-3">
                            {d.records.map((r) => (
                                <div key={r.key} className="rounded-xl border border-border p-3">
                                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px]">{r.type}</span>
                                            {r.key === 'verify' ? 'Ownership' : r.key === 'dkim' ? 'DKIM key' : r.key.toUpperCase()}
                                            {!r.required && <span className="text-xs font-normal text-muted-foreground">(optional)</span>}
                                        </div>
                                        {d.checks[r.key] && <StatusPill status={CHECK[d.checks[r.key]]?.status ?? 'unknown'} label={CHECK[d.checks[r.key]]?.label} />}
                                    </div>
                                    <div className="grid gap-1.5 text-xs">
                                        <div className="flex items-start gap-2"><span className="w-12 shrink-0 text-muted-foreground">Name</span><code className="min-w-0 flex-1 break-all">{r.host}</code><Copy value={r.host} /></div>
                                        <div className="flex items-start gap-2"><span className="w-12 shrink-0 text-muted-foreground">Value</span><code className="min-w-0 flex-1 break-all rounded bg-muted/50 p-1.5">{r.value}</code><Copy value={r.value} /></div>
                                    </div>
                                    <p className="mt-2 text-[11px] text-muted-foreground">{r.purpose}</p>
                                </div>
                            ))}
                        </div>
                        {d.checked && <p className="mt-3 text-[11px] text-muted-foreground">Last checked {d.checked}.</p>}
                    </Section>
                ))}
            </div>
        </>
    );
}

HostEdmDomains.layout = { breadcrumbs: [HOST_EDM_CRUMB, { title: 'Sending domain', href: '/host/edm/domains' }] };
