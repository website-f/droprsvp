import { Head } from '@inertiajs/react';
import { Banknote, CalendarDays, Megaphone, Ticket, Users, Wallet } from 'lucide-react';

interface Stats { organizers: number; events: number; published: number; tickets_sold: number; gross: number; platform_fees: number; pending_payouts: number; paid_out: number }

function Card({ icon: Icon, label, value }: { icon: React.ComponentType<{ className?: string }>; label: string; value: string }) {
    return (
        <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <div className="flex items-center gap-2 text-xs uppercase tracking-wide text-muted-foreground"><Icon className="size-4" /> {label}</div>
            <div className="mt-2 text-2xl font-bold tabular-nums">{value}</div>
        </div>
    );
}

export default function Overview({ stats, boost_revenue }: { stats: Stats; boost_revenue: number }) {
    const rm = (n: number) => `RM ${n.toFixed(2)}`;

    return (
        <>
            <Head title="Platform overview" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <h1 className="mb-6 text-2xl font-bold tracking-tight">Platform overview</h1>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card icon={Wallet} label="Gross revenue" value={rm(stats.gross)} />
                    <Card icon={Banknote} label="Platform fees" value={rm(stats.platform_fees)} />
                    <Card icon={Ticket} label="Tickets sold" value={String(stats.tickets_sold)} />
                    <Card icon={CalendarDays} label="Events" value={`${stats.published}/${stats.events}`} />
                    <Card icon={Users} label="Organizers" value={String(stats.organizers)} />
                    <Card icon={Banknote} label="Pending payouts" value={rm(stats.pending_payouts)} />
                    <Card icon={Megaphone} label="Boost revenue" value={rm(boost_revenue)} />
                </div>

                <p className="mt-6 text-sm text-muted-foreground">Fees, tax and other configuration live under <a href="/admin/settings" className="font-medium text-foreground underline underline-offset-2">Settings</a>.</p>
            </div>
        </>
    );
}

Overview.layout = {
    breadcrumbs: [{ title: 'Overview', href: '/admin/overview' }],
};
