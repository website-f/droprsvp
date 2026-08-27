import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Banknote, CalendarDays, Ticket, Users, Wallet } from 'lucide-react';

interface Stats { organizers: number; events: number; published: number; tickets_sold: number; gross: number; platform_fees: number; pending_payouts: number; paid_out: number }

function Card({ icon: Icon, label, value }: { icon: React.ComponentType<{ className?: string }>; label: string; value: string }) {
    return (
        <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <div className="flex items-center gap-2 text-xs uppercase tracking-wide text-muted-foreground"><Icon className="size-4" /> {label}</div>
            <div className="mt-2 text-2xl font-bold tabular-nums">{value}</div>
        </div>
    );
}

export default function Overview({ stats, fee_percent }: { stats: Stats; fee_percent: number }) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const form = useForm({ fee_percent: String(fee_percent) });
    const rm = (n: number) => `RM ${n.toFixed(2)}`;

    return (
        <>
            <Head title="Platform overview" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <h1 className="mb-6 text-2xl font-bold tracking-tight">Platform overview</h1>
                {flash?.success && <div className="mb-4 rounded-lg border border-foreground bg-foreground p-3 text-sm text-background">{flash.success}</div>}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card icon={Wallet} label="Gross revenue" value={rm(stats.gross)} />
                    <Card icon={Banknote} label="Platform fees" value={rm(stats.platform_fees)} />
                    <Card icon={Ticket} label="Tickets sold" value={String(stats.tickets_sold)} />
                    <Card icon={CalendarDays} label="Events" value={`${stats.published}/${stats.events}`} />
                    <Card icon={Users} label="Organizers" value={String(stats.organizers)} />
                    <Card icon={Banknote} label="Pending payouts" value={rm(stats.pending_payouts)} />
                    <Card icon={Banknote} label="Paid out" value={rm(stats.paid_out)} />
                </div>

                <div className="mt-6 max-w-md rounded-xl border border-border bg-card p-5 shadow-sm">
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Platform fee</h2>
                    <p className="mt-1 text-xs text-muted-foreground">Percentage kept from each organizer’s gross ticket revenue.</p>
                    <form onSubmit={(e) => { e.preventDefault(); form.post('/admin/settings/fee', { preserveScroll: true }); }} className="mt-3 flex items-end gap-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="fee">Fee (%)</Label>
                            <input id="fee" type="number" min={0} max={100} step="0.1" className="h-10 w-32 rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" value={form.data.fee_percent} onChange={(e) => form.setData('fee_percent', e.target.value)} />
                        </div>
                        <Button type="submit" disabled={form.processing}>Save</Button>
                    </form>
                    {form.errors.fee_percent && <p className="mt-2 text-xs text-destructive">{form.errors.fee_percent}</p>}
                </div>
            </div>
        </>
    );
}

Overview.layout = {
    breadcrumbs: [{ title: 'Overview', href: '/admin/overview' }],
};
