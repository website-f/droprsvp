import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';

type Prefs = Record<string, boolean>;

export default function NotificationsSettings({ channels, preferences }: { channels: Record<string, string>; preferences: Prefs }) {
    const form = useForm<Prefs>(preferences);
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
