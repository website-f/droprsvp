import { router, useForm } from '@inertiajs/react';
import { CheckCircle2, Copy, PlugZap } from 'lucide-react';
import { toast } from 'sonner';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

export interface AirwallexForm {
    enabled: boolean; env: string; client_id: string; account_id: string; api_version: string;
    api_key_set: boolean; api_key_hint: string | null; webhook_secret_set: boolean; configured: boolean;
}

const input = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

/**
 * Settings → Airwallex: card payments for events outside Malaysia (CHIP stays
 * for Malaysia). Keys are entered here rather than in .env, saved encrypted,
 * and never shown again — a saved secret is only reported as "saved ••••1234".
 */
export function AirwallexSettings({ initial, webhookUrl }: { initial: AirwallexForm; webhookUrl: string }) {
    const form = useForm({
        enabled: initial.enabled,
        env: initial.env,
        client_id: initial.client_id,
        account_id: initial.account_id,
        api_version: initial.api_version,
        api_key: '',
        webhook_secret: '',
        clear_api_key: false,
        clear_webhook_secret: false,
    });
    const { data, setData, processing, errors } = form;

    const save = () => form.post('/admin/settings/airwallex', {
        preserveScroll: true,
        onSuccess: () => setData((d) => ({ ...d, api_key: '', webhook_secret: '', clear_api_key: false, clear_webhook_secret: false })),
    });

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(webhookUrl);
            toast.success('Webhook URL copied.');
        } catch {
            toast.error('Couldn’t copy — select the address and copy it.');
        }
    };

    return (
        <div className="grid gap-5">
            <div className="flex items-start justify-between gap-4 rounded-lg border border-border p-3">
                <div>
                    <div className="flex items-center gap-2 text-sm font-medium">
                        Take payments through Airwallex outside Malaysia
                        {initial.configured && <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"><CheckCircle2 className="size-3" /> Live</span>}
                    </div>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Malaysia always uses CHIP (FPX, DuitNow, cards in ringgit). With this on, events in other countries are paid by card on Airwallex’s secure page, in that country’s currency (see Country rates).
                    </p>
                </div>
                <Switch checked={data.enabled} onCheckedChange={(v) => setData('enabled', v)} aria-label="Use Airwallex" />
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label>Environment</Label>
                    <AppSelect value={data.env} onChange={(v) => setData('env', v)} options={[
                        { value: 'sandbox', label: 'Sandbox (testing — no real money)' },
                        { value: 'production', label: 'Production (live payments)' },
                    ]} />
                    <p className="text-xs text-muted-foreground">Sandbox and live have separate keys in Airwallex.</p>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="aw-account">Account ID</Label>
                    <input id="aw-account" className={input} value={data.account_id} onChange={(e) => setData('account_id', e.target.value)} placeholder="acct_…" autoComplete="off" />
                    {errors.account_id && <p className="text-xs text-destructive">{errors.account_id}</p>}
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="aw-client">Client ID</Label>
                    <input id="aw-client" className={input} value={data.client_id} onChange={(e) => setData('client_id', e.target.value)} autoComplete="off" />
                    <p className="text-xs text-muted-foreground">Airwallex → Developer → API keys.</p>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="aw-key">API key</Label>
                    <input id="aw-key" type="password" className={input} value={data.api_key} onChange={(e) => setData('api_key', e.target.value)}
                        placeholder={initial.api_key_set ? `Saved ${initial.api_key_hint ?? ''} — leave blank to keep` : 'Paste the API key'} autoComplete="new-password" />
                    {initial.api_key_set && (
                        <label className="flex items-center gap-2 text-xs text-muted-foreground"><input type="checkbox" checked={data.clear_api_key} onChange={(e) => setData('clear_api_key', e.target.checked)} /> Remove the saved key</label>
                    )}
                </div>
                <div className="grid gap-1.5 sm:col-span-2">
                    <Label>Webhook</Label>
                    <div className="flex gap-2">
                        <input readOnly className={`${input} font-mono text-xs`} value={webhookUrl} onFocus={(e) => e.target.select()} />
                        <Button type="button" variant="outline" onClick={copy}><Copy className="size-4" /> Copy</Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        In Airwallex → Developer → Webhooks, add this address and tick <code>payment_intent.succeeded</code> and <code>checkout_session.completed</code>. Then paste the webhook’s secret below.
                    </p>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="aw-secret">Webhook secret</Label>
                    <input id="aw-secret" type="password" className={input} value={data.webhook_secret} onChange={(e) => setData('webhook_secret', e.target.value)}
                        placeholder={initial.webhook_secret_set ? 'Saved — leave blank to keep' : 'Paste the webhook secret'} autoComplete="new-password" />
                    {initial.webhook_secret_set && (
                        <label className="flex items-center gap-2 text-xs text-muted-foreground"><input type="checkbox" checked={data.clear_webhook_secret} onChange={(e) => setData('clear_webhook_secret', e.target.checked)} /> Remove the saved secret</label>
                    )}
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="aw-version">API version <span className="font-normal text-muted-foreground">— optional</span></Label>
                    <input id="aw-version" className={input} value={data.api_version} onChange={(e) => setData('api_version', e.target.value)} placeholder="2026-08-21" />
                    {errors.api_version && <p className="text-xs text-destructive">Use the format YYYY-MM-DD.</p>}
                </div>
            </div>

            <div className="flex flex-wrap justify-end gap-2">
                <Button type="button" variant="outline" disabled={processing || !initial.api_key_set}
                    onClick={() => router.post('/admin/settings/airwallex/test', {}, { preserveScroll: true })}>
                    <PlugZap className="size-4" /> Test connection
                </Button>
                <Button type="button" disabled={processing} onClick={save}>Save Airwallex</Button>
            </div>
        </div>
    );
}
