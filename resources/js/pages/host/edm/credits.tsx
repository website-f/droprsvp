import { Head, Link, router } from '@inertiajs/react';
import { Check, Coins, Crown } from 'lucide-react';
import { useState } from 'react';
import { EdmHeader, HOST_EDM_CRUMB, Pager, Section } from '@/components/edm/ui';
import { ResponsiveTable } from '@/components/responsive-table';
import { Button } from '@/components/ui/button';

interface Entry { id: number; pool: 'allowance' | 'credits'; delta: number; reason: string; note: string | null; campaign: { id: number; name: string } | null; when: string | null }
interface Props {
    credits: { allowance: number; allowance_used: number; allowance_left: number; credits: number; available: number; premium: boolean; period: string };
    packs: { key: string; name: string; credits: number; price: number }[];
    premiumAllowance: number;
    ledger: { data: Entry[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number };
    purchases: { reference: string; credits: number; amount: number; status: string; when: string | null }[];
}

const REASON: Record<string, string> = { purchase: 'Bought', reserve: 'Campaign', refund: 'Unsent, returned', adjust: 'Adjusted by DropRSVP' };

export default function HostEdmCredits({ credits, packs, premiumAllowance, ledger, purchases }: Props) {
    const [buying, setBuying] = useState('');
    const best = packs.reduce<{ key: string; per: number } | null>((b, p) => (!b || p.price / p.credits < b.per ? { key: p.key, per: p.price / p.credits } : b), null);

    const buy = (key: string) => {
        setBuying(key);
        router.post('/host/edm/credits', { pack: key }, { onFinish: () => setBuying('') });
    };

    return (
        <>
            <Head title="Email credits" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <EdmHeader title="Email credits" description="Each email sent uses one credit. A campaign reserves what it needs when it starts, and anything that is not sent comes back." />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <div className="text-xs text-muted-foreground">You can send</div>
                        <div className="mt-1 text-3xl font-bold tabular-nums">{credits.available.toLocaleString()}</div>
                        <div className="text-xs text-muted-foreground">emails right now</div>
                    </div>
                    <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <div className="text-xs text-muted-foreground">Free this month · {credits.period}</div>
                        <div className="mt-1 text-3xl font-bold tabular-nums">{credits.allowance_left.toLocaleString()}</div>
                        <div className="text-xs text-muted-foreground">
                            {credits.allowance > 0 ? `of ${credits.allowance.toLocaleString()} · resets on the 1st` : <span className="inline-flex items-center gap-1"><Crown className="size-3" /> Premium includes {premiumAllowance.toLocaleString()} a month. <Link href="/premium" className="font-medium text-foreground underline">Upgrade</Link></span>}
                        </div>
                    </div>
                    <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <div className="text-xs text-muted-foreground">Bought credits</div>
                        <div className="mt-1 text-3xl font-bold tabular-nums">{credits.credits.toLocaleString()}</div>
                        <div className="text-xs text-muted-foreground">never expire</div>
                    </div>
                </div>

                <h2 className="mb-3 mt-8 flex items-center gap-2 text-sm font-semibold"><Coins className="size-4" /> Buy credits</h2>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    {packs.map((p) => (
                        <div key={p.key} className={`relative flex flex-col rounded-2xl border bg-card p-5 shadow-sm ${best?.key === p.key ? 'border-primary ring-1 ring-primary' : 'border-border'}`}>
                            {best?.key === p.key && <span className="absolute -top-2.5 left-5 rounded-full bg-primary px-2 py-0.5 text-[11px] font-medium text-primary-foreground">Best value</span>}
                            <div className="font-semibold">{p.name}</div>
                            <div className="mt-2 text-3xl font-bold tabular-nums">RM {p.price.toFixed(0)}</div>
                            <div className="text-sm text-muted-foreground">{p.credits.toLocaleString()} emails · RM {(p.price / p.credits * 1000).toFixed(2)} per 1,000</div>
                            <ul className="my-4 grid gap-1 text-xs text-muted-foreground">
                                <li className="flex items-center gap-1.5"><Check className="size-3.5 text-emerald-600" /> Never expire</li>
                                <li className="flex items-center gap-1.5"><Check className="size-3.5 text-emerald-600" /> Unsent emails returned</li>
                            </ul>
                            <Button className="mt-auto" variant={best?.key === p.key ? 'default' : 'outline'} disabled={buying !== ''} onClick={() => buy(p.key)}>
                                {buying === p.key ? 'Opening payment…' : `Buy ${p.credits.toLocaleString()}`}
                            </Button>
                        </div>
                    ))}
                </div>
                <p className="mt-2 text-xs text-muted-foreground">Paid securely through CHIP (FPX, cards and e-wallets). Credits appear as soon as the payment clears.</p>

                {purchases.length > 0 && (
                    <Section className="mt-8" padded={false} title="Purchases">
                        <ResponsiveTable>
                            <table className="w-full min-w-[520px] text-sm">
                                <thead className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <tr className="border-b border-border"><th className="px-4 py-2 font-medium">Reference</th><th className="px-4 py-2 text-right font-medium">Credits</th><th className="px-4 py-2 text-right font-medium">Amount</th><th className="px-4 py-2 font-medium">Status</th><th className="px-4 py-2 font-medium">Date</th></tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {purchases.map((p) => (
                                        <tr key={p.reference}>
                                            <td className="px-4 py-2 font-mono text-xs">{p.reference}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{p.credits.toLocaleString()}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">RM {p.amount.toFixed(2)}</td>
                                            <td className="px-4 py-2 capitalize">{p.status}</td>
                                            <td className="px-4 py-2 text-muted-foreground">{p.when}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </ResponsiveTable>
                    </Section>
                )}

                <Section className="mt-4" padded={false} title="History" description="Every change to your credits.">
                    <ResponsiveTable>
                        <table className="w-full min-w-[620px] text-sm">
                            <thead className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr className="border-b border-border"><th className="px-4 py-2 font-medium">What</th><th className="px-4 py-2 font-medium">From</th><th className="px-4 py-2 text-right font-medium">Change</th><th className="px-4 py-2 font-medium">When</th></tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {ledger.data.length === 0 ? (
                                    <tr><td colSpan={4} className="px-4 py-10 text-center text-muted-foreground">Nothing yet.</td></tr>
                                ) : ledger.data.map((e) => (
                                    <tr key={e.id}>
                                        <td className="px-4 py-2">
                                            <div className="font-medium">{REASON[e.reason] ?? e.reason}</div>
                                            <div className="text-xs text-muted-foreground">{e.campaign ? <Link href={`/host/edm/campaigns/${e.campaign.id}`} className="hover:underline">{e.campaign.name}</Link> : e.note}</div>
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">{e.pool === 'allowance' ? 'Free monthly' : 'Credits'}</td>
                                        <td className={`px-4 py-2 text-right font-semibold tabular-nums ${e.delta < 0 ? '' : 'text-emerald-600 dark:text-emerald-400'}`}>{e.delta > 0 ? '+' : ''}{e.delta.toLocaleString()}</td>
                                        <td className="whitespace-nowrap px-4 py-2 text-xs text-muted-foreground">{e.when}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </ResponsiveTable>
                    <Pager prev={ledger.prev_page_url} next={ledger.next_page_url} page={ledger.current_page} last={ledger.last_page} />
                </Section>
            </div>
        </>
    );
}

HostEdmCredits.layout = { breadcrumbs: [HOST_EDM_CRUMB, { title: 'Credits', href: '/host/edm/credits' }] };
