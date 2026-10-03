/**
 * The organizer email rules, shared by EDM → Organizer rules (everyone),
 * the per-organizer rules dialog, and the organizer's own overview.
 */

export type LimitKey = 'hourly_limit' | 'daily_limit' | 'weekly_limit' | 'max_recipients' | 'campaigns_per_day' | 'campaigns_per_week' | 'per_person_per_week';
export type FeatureKey = 'automations' | 'abandoned_checkout' | 'domains';
export type Rules = Record<LimitKey, number> & Record<FeatureKey, boolean>;
export interface Usage { used: number; limit: number }
export type UsageMap = Record<'hour' | 'day' | 'week' | 'campaigns_day' | 'campaigns_week', Usage>;

export const LIMIT_FIELDS: { key: LimitKey; label: string; hint: string; group: 'volume' | 'frequency' }[] = [
    { key: 'hourly_limit', label: 'Emails per hour', hint: 'Sending is paced: a campaign carries on in the next hour.', group: 'volume' },
    { key: 'daily_limit', label: 'Emails per day', hint: 'Rolling 24 hours.', group: 'volume' },
    { key: 'weekly_limit', label: 'Emails per week', hint: 'Rolling 7 days.', group: 'volume' },
    { key: 'max_recipients', label: 'Recipients per campaign', hint: 'A bigger audience must be narrowed before it can start.', group: 'frequency' },
    { key: 'campaigns_per_day', label: 'Campaigns per day', hint: '1 = at most one campaign a day.', group: 'frequency' },
    { key: 'campaigns_per_week', label: 'Campaigns per week', hint: 'Rolling 7 days.', group: 'frequency' },
    { key: 'per_person_per_week', label: 'Emails one person gets, per week', hint: 'From one organizer. Booking reminders don’t count.', group: 'frequency' },
];

export const FEATURE_FIELDS: { key: FeatureKey; label: string; hint: string }[] = [
    { key: 'automations', label: 'Automations', hint: 'Event reminders, thank-yous after an event, welcome emails.' },
    { key: 'abandoned_checkout', label: 'Abandoned-checkout reminders', hint: 'These write to people who did NOT buy a ticket — outside the “joined only” rule. Off unless you allow it.' },
    { key: 'domains', label: 'Send from their own domain', hint: 'DKIM-signed from you@theirdomain.com once DNS is verified.' },
];

export const ACCESS_MODES: { value: 'all' | 'premium' | 'selected'; label: string; hint: string }[] = [
    { value: 'all', label: 'Every organizer', hint: 'Any approved organizer can use it.' },
    { value: 'premium', label: 'Premium organizers', hint: 'Others see what it does and a Go Premium button.' },
    { value: 'selected', label: 'By invitation', hint: 'Only organizers you switch on in EDM → Organizers.' },
];

export const limitText = (n: number) => (n > 0 ? n.toLocaleString() : 'No limit');

/** Where an organizer stands against each volume and frequency limit. */
export function UsageMeters({ usage, compact = false }: { usage: UsageMap; compact?: boolean }) {
    const rows: [keyof UsageMap, string][] = [
        ['hour', 'This hour'],
        ['day', 'Last 24 hours'],
        ['week', 'Last 7 days'],
        ['campaigns_day', 'Campaigns today'],
        ['campaigns_week', 'Campaigns this week'],
    ];

    return (
        <div className={`grid gap-3 ${compact ? '' : 'sm:grid-cols-2'}`}>
            {rows.map(([key, label]) => {
                const { used, limit } = usage[key];
                const ratio = limit > 0 ? Math.min(1, used / limit) : 0;
                // Reaching a limit is normal (sending waits for the window), not an error.
                const tone = ratio >= 0.75 ? 'bg-amber-500' : 'bg-emerald-500';

                return (
                    <div key={key} className="grid gap-1">
                        <div className="flex justify-between gap-2 text-xs">
                            <span>{label}</span>
                            <span className="tabular-nums text-muted-foreground">{used.toLocaleString()} / {limitText(limit)}{limit > 0 && used >= limit && <span className="font-medium text-amber-600 dark:text-amber-400"> · reached</span>}</span>
                        </div>
                        <div className="h-2 overflow-hidden rounded-full bg-muted">
                            {limit > 0 && <div className={`h-full rounded-full ${tone}`} style={{ width: `${Math.max(2, ratio * 100)}%` }} />}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
