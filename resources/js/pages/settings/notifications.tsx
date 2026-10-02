import { Head, router, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';

type Prefs = Record<string, boolean>;

export default function NotificationsSettings({ channels, preferences, marketingEmail = false, organizerLists = [] }: {
    channels: Record<string, string>;
    preferences: Prefs;
    marketingEmail?: boolean;
    organizerLists?: { id: number; name: string; since: string | null }[];
}) {
    const form = useForm<Prefs>({ ...preferences, marketing_email: marketingEmail });
    const keys = Object.keys(channels);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/notifications', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Notification settings" />

            <h1 className="sr-only">Notification settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Notifications"
                    description="Choose which updates land in your inbox. Receipts, tickets and refund decisions are always sent."
                />

                <form onSubmit={submit} className="space-y-6">
                    <div className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                        {keys.map((key) => (
                            <label key={key} htmlFor={`pref-${key}`} className="flex cursor-pointer items-center justify-between gap-4 px-4 py-3.5">
                                <span className="text-sm text-foreground">{channels[key]}</span>
                                <Switch
                                    id={`pref-${key}`}
                                    checked={form.data[key]}
                                    onCheckedChange={(v) => form.setData(key, v)}
                                    aria-label={channels[key]}
                                />
                            </label>
                        ))}
                    </div>

                    {/* Separate from the in-app channels above: this is consent to
                        marketing EMAIL, and it is off until they turn it on. */}
                    <div className="overflow-hidden rounded-xl border border-border">
                        <label htmlFor="pref-marketing_email" className="flex cursor-pointer items-center justify-between gap-4 px-4 py-3.5">
                            <span>
                                <span className="block text-sm text-foreground">Emails about upcoming events and offers</span>
                                <span className="block text-xs text-muted-foreground">Our newsletter. Unsubscribe here or from any email.</span>
                            </span>
                            <Switch
                                id="pref-marketing_email"
                                checked={form.data.marketing_email}
                                onCheckedChange={(v) => form.setData('marketing_email', v)}
                                aria-label="Emails about upcoming events and offers"
                            />
                        </label>
                    </div>

                    {/* Each organizer is a list of its own: leaving one leaves the others. */}
                    {organizerLists.length > 0 && (
                        <div className="overflow-hidden rounded-xl border border-border">
                            <div className="border-b border-border px-4 py-3">
                                <div className="text-sm text-foreground">Organizers you hear from</div>
                                <div className="text-xs text-muted-foreground">You chose these at checkout. Each is separate from our newsletter.</div>
                            </div>
                            <ul className="divide-y divide-border">
                                {organizerLists.map((o) => (
                                    <li key={o.id} className="flex items-center justify-between gap-4 px-4 py-3">
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm text-foreground">{o.name}</span>
                                            {o.since && <span className="block text-xs text-muted-foreground">Since {o.since}</span>}
                                        </span>
                                        <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/settings/notifications/organizers/${o.id}/unsubscribe`, {}, { preserveScroll: true })}>
                                            Unsubscribe
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save preferences'}</Button>
                        {form.recentlySuccessful && <span className="text-sm text-muted-foreground">Saved.</span>}
                    </div>
                </form>
            </div>
        </>
    );
}

NotificationsSettings.layout = {
    breadcrumbs: [{ title: 'Notification settings', href: '/settings/notifications' }],
};
