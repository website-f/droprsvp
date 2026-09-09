import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Eye,
    Percent,
    Search,
    SlidersHorizontal,
} from 'lucide-react';
import { useState } from 'react';
import { OrganizerFeeDialog } from '@/components/admin/organizer-fee-dialog';
import type {
    FeeRate,
    FeeTarget,
} from '@/components/admin/organizer-fee-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Row {
    id: number;
    name: string;
    email: string;
    events: number;
    fees_earned: number;
    fee: FeeRate;
}
interface Paginated {
    data: Row[];
    prev_page_url: string | null;
    next_page_url: string | null;
}
interface Filters {
    q: string;
    scope: string;
}
interface Props {
    organizers: Paginated;
    filters: Filters;
    global: FeeRate;
    counts: { custom: number };
}

const rm = (n: number) =>
    `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const rate = (f: FeeRate) => `${f.percent}% or RM${f.flat.toFixed(2)}`;

export default function OrganizerFees({
    organizers,
    filters,
    global: globalFee,
    counts,
}: Props) {
    const flash = usePage().props.flash as
        { success?: string; error?: string } | undefined;
    const [q, setQ] = useState(filters.q);
    const [editing, setEditing] = useState<FeeTarget | null>(null);

    const go = (patch: Partial<Filters>) => {
        const next = { ...filters, q, ...patch };
        const params = Object.fromEntries(
            Object.entries(next).filter(([, v]) => v && v !== 'all'),
        );
        router.get('/admin/organizer-fees', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const SCOPES = [
        { v: 'all', l: 'All organizers' },
        {
            v: 'custom',
            l: counts.custom ? `Custom fee (${counts.custom})` : 'Custom fee',
        },
        { v: 'global', l: 'On global fee' },
    ];

    return (
        <>
            <Head title="Organizer fees" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <Link
                    href="/admin/settings"
                    className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> Back to settings
                </Link>

                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight">
                        Organizer fees
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Override the platform booking fee for a single organizer
                        — for a negotiated deal, a promo period or a high-volume
                        host.
                    </p>
                </div>

                {flash?.success && (
                    <div className="mb-4 rounded-lg border border-foreground bg-foreground p-3 text-sm text-background">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="mb-4 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
                        {flash.error}
                    </div>
                )}

                {/* The rate everyone falls back to. */}
                <div className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-muted/30 p-4">
                    <div className="flex items-center gap-3">
                        <span className="flex size-9 items-center justify-center rounded-lg bg-card">
                            <Percent className="size-4" />
                        </span>
                        <div>
                            <div className="text-sm font-medium">
                                Global booking fee — {rate(globalFee)}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                Charged to buyers of every organizer without an
                                override below.
                            </div>
                        </div>
                    </div>
                    <Button asChild variant="outline" size="sm">
                        <Link href="/admin/settings">Edit global fee</Link>
                    </Button>
                </div>

                {/* Scope tabs */}
                <div className="mb-4 flex flex-wrap gap-2">
                    {SCOPES.map((t) => (
                        <button
                            key={t.v}
                            onClick={() => go({ scope: t.v })}
                            className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${filters.scope === t.v ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}
                        >
                            {t.l}
                        </button>
                    ))}
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        go({});
                    }}
                    className="mb-5 flex gap-2"
                >
                    <label className="flex h-10 flex-1 items-center gap-2 rounded-lg border border-input bg-card px-3">
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input
                            className="w-full bg-transparent text-sm outline-none"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search organizer name or email"
                        />
                    </label>
                    <Button type="submit">Search</Button>
                </form>

                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-left text-xs tracking-wide text-muted-foreground uppercase">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    Organizer
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Events
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Fees collected
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Booking fee
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {organizers.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-10 text-center text-sm text-muted-foreground"
                                    >
                                        No organizers match these filters.
                                    </td>
                                </tr>
                            ) : (
                                organizers.data.map((o) => (
                                    <tr
                                        key={o.id}
                                        className="hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {o.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {o.email}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {o.events.toLocaleString()}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {rm(o.fees_earned)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium">
                                                    {rate(o.fee)}
                                                </span>
                                                {o.fee.custom ? (
                                                    <Badge>Custom</Badge>
                                                ) : (
                                                    <Badge
                                                        variant="secondary"
                                                        className="font-normal"
                                                    >
                                                        Global
                                                    </Badge>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        setEditing({
                                                            id: o.id,
                                                            name: o.name,
                                                            fee: o.fee,
                                                        })
                                                    }
                                                >
                                                    <SlidersHorizontal className="size-3.5" />{' '}
                                                    Edit fee
                                                </Button>
                                                <Button
                                                    asChild
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    title="View organizer"
                                                >
                                                    <Link
                                                        href={`/admin/users/${o.id}`}
                                                        aria-label={`View ${o.name}`}
                                                    >
                                                        <Eye className="size-4" />
                                                    </Link>
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {(organizers.prev_page_url || organizers.next_page_url) && (
                    <div className="mt-6 flex justify-between">
                        <Button
                            asChild
                            variant="outline"
                            disabled={!organizers.prev_page_url}
                        >
                            {organizers.prev_page_url ? (
                                <Link href={organizers.prev_page_url}>
                                    ← Previous
                                </Link>
                            ) : (
                                <span>← Previous</span>
                            )}
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            disabled={!organizers.next_page_url}
                        >
                            {organizers.next_page_url ? (
                                <Link href={organizers.next_page_url}>
                                    Next →
                                </Link>
                            ) : (
                                <span>Next →</span>
                            )}
                        </Button>
                    </div>
                )}
            </div>

            <OrganizerFeeDialog
                target={editing}
                global={globalFee}
                onClose={() => setEditing(null)}
            />
        </>
    );
}

OrganizerFees.layout = {
    breadcrumbs: [
        { title: 'Settings', href: '/admin/settings' },
        { title: 'Organizer fees', href: '/admin/organizer-fees' },
    ],
};
