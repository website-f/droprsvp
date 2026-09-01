import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, BadgeCheck, CalendarDays, Heart, Mail, MapPin, Phone, ShieldCheck, Ticket, Trash2, Users as UsersIcon, Wallet } from 'lucide-react';
import { useConfirm } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface UserDetail {
    id: number; name: string; email: string; phone: string | null;
    gender: string | null; age_band: string | null; city: string | null; country: string | null;
    roles: string[]; is_superadmin: boolean; profile_complete: boolean; profile_completed_at: string | null;
    email_verified: boolean; joined: string | null;
}
interface Activity { events: number; orders: number; tickets: number; spent: number; followers: number; following: number }

const AGE_LABEL: Record<string, string> = { 'under-18': 'Under 18', '18-24': '18–24', '25-34': '25–34', '35-44': '35–44', '45-54': '45–54', '55+': '55+' };
const initials = (n: string) => n.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';
const rm = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const cap = (s: string | null) => (s && s !== 'na' ? s.charAt(0).toUpperCase() + s.slice(1) : '—');

function Stat({ icon: Icon, label, value, tint }: { icon: typeof Ticket; label: string; value: string; tint: string }) {
    return (
        <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
            <span className="flex size-9 items-center justify-center rounded-xl" style={{ backgroundColor: `${tint}1f`, color: tint }}><Icon className="size-4" /></span>
            <div className="mt-3 text-xl font-bold tracking-tight">{value}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
        </div>
    );
}

function Row({ icon: Icon, label, children }: { icon: typeof Mail; label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-start gap-3 py-3">
            <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <div className="min-w-0 flex-1">
                <div className="text-xs text-muted-foreground">{label}</div>
                <div className="mt-0.5 break-words text-sm font-medium">{children}</div>
            </div>
        </div>
    );
}

export default function UserShow({ user, activity }: { user: UserDetail; activity: Activity }) {
    const flash = usePage().props.flash as { success?: string; error?: string } | undefined;
    const confirm = useConfirm();

    const toggle = async () => {
        const verb = user.is_superadmin ? 'Revoke superadmin from' : 'Grant superadmin to';

        if (await confirm({ title: `${verb} ${user.name}?`, confirmText: user.is_superadmin ? 'Revoke' : 'Grant', destructive: user.is_superadmin })) {
            router.post(`/admin/users/${user.id}/superadmin`, {}, { preserveScroll: true });
        }
    };

    const remove = async () => {
        if (await confirm({ title: `Delete ${user.name}?`, description: 'They’ll be moved to the Archive — you can restore or permanently delete them there.', confirmText: 'Delete', destructive: true })) {
            router.delete(`/admin/users/${user.id}`, { onSuccess: () => router.visit('/admin/users') });
        }
    };

    return (
        <>
            <Head title={user.name} />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <Link href="/admin/users" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> Back to users</Link>

                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}
                {flash?.error && <div className="mb-4 rounded-lg bg-destructive/10 px-4 py-2 text-sm text-destructive">{flash.error}</div>}

                {/* Header */}
                <div className="flex flex-col gap-4 rounded-2xl border border-border bg-card p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-4">
                        <span className="flex size-14 shrink-0 items-center justify-center rounded-full bg-foreground text-lg font-bold text-background">{initials(user.name)}</span>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-bold tracking-tight">{user.name}</h1>
                                {user.roles.map((r) => <Badge key={r} variant="secondary" className="capitalize">{r}</Badge>)}
                            </div>
                            <a href={`mailto:${user.email}`} className="mt-0.5 block truncate text-sm text-muted-foreground hover:text-foreground">{user.email}</a>
                        </div>
                    </div>
                    <div className="flex shrink-0 gap-2">
                        <Button variant={user.is_superadmin ? 'outline' : 'default'} onClick={toggle}>
                            <ShieldCheck className="size-4" /> {user.is_superadmin ? 'Revoke admin' : 'Make admin'}
                        </Button>
                        {!user.is_superadmin && (
                            <Button variant="outline" onClick={remove} className="text-destructive hover:text-destructive">
                                <Trash2 className="size-4" /> Delete
                            </Button>
                        )}
                    </div>
                </div>

                {/* Activity */}
                <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <Stat icon={Ticket} label="Tickets" value={activity.tickets.toLocaleString()} tint="#6c63ff" />
                    <Stat icon={Wallet} label="Spent" value={rm(activity.spent)} tint="#2ec4b6" />
                    <Stat icon={CalendarDays} label="Orders" value={activity.orders.toLocaleString()} tint="#f5a524" />
                    <Stat icon={BadgeCheck} label="Events hosted" value={activity.events.toLocaleString()} tint="#3b82f6" />
                    <Stat icon={UsersIcon} label="Followers" value={activity.followers.toLocaleString()} tint="#ff6584" />
                    <Stat icon={Heart} label="Following" value={activity.following.toLocaleString()} tint="#a855f7" />
                </div>

                {/* Details */}
                <div className="mt-5 grid gap-4 md:grid-cols-2">
                    <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <h2 className="mb-1 text-sm font-semibold">Contact</h2>
                        <div className="divide-y divide-border">
                            <Row icon={Mail} label="Email"><a href={`mailto:${user.email}`} className="hover:underline">{user.email}</a> {user.email_verified && <span className="ml-1 text-xs text-[#2ec4b6]">✓ verified</span>}</Row>
                            <Row icon={Phone} label="Phone">{user.phone ? <a href={`tel:${user.phone}`} className="hover:underline">{user.phone}</a> : '—'}</Row>
                            <Row icon={MapPin} label="Location">{[user.city, user.country].filter(Boolean).join(', ') || '—'}</Row>
                        </div>
                    </section>

                    <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <h2 className="mb-1 text-sm font-semibold">About &amp; account</h2>
                        <div className="divide-y divide-border">
                            <Row icon={UsersIcon} label="Gender">{cap(user.gender)}</Row>
                            <Row icon={CalendarDays} label="Age band">{user.age_band ? (AGE_LABEL[user.age_band] ?? user.age_band) : '—'}</Row>
                            <Row icon={BadgeCheck} label="Profile">{user.profile_complete ? `Completed${user.profile_completed_at ? ` · ${user.profile_completed_at}` : ''}` : 'Incomplete'}</Row>
                            <Row icon={CalendarDays} label="Joined">{user.joined ?? '—'}</Row>
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}

UserShow.layout = {
    breadcrumbs: [{ title: 'Users', href: '/admin/users' }, { title: 'Details', href: '#' }],
};
