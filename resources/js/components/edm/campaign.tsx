import { Badge } from '@/components/ui/badge';

/** Shared by the campaign list and the campaign page. */

export interface CampaignSummary {
    id: number; name: string; status: string; kind: string;
    recipients: number; sent: number; failed: number; opened: number; clicked: number; bounced: number; unsubscribed: number;
    open_rate: number | null; click_rate: number | null;
    scheduled_at: string | null; started_at: string | null; finished_at: string | null; updated_at: string | null;
    editable: boolean;
}

export interface ThrottleStatus {
    hourly_limit: number; per_minute: number; sent_last_hour: number; sent_last_day: number;
    warmup_daily_cap: number | null; budget_now: number;
}

export function statusBadge(status: string) {
    const map: Record<string, { label: string; variant: 'default' | 'secondary' | 'outline' | 'destructive' }> = {
        draft: { label: 'Draft', variant: 'outline' },
        scheduled: { label: 'Scheduled', variant: 'secondary' },
        sending: { label: 'Sending', variant: 'default' },
        paused: { label: 'Paused', variant: 'destructive' },
        sent: { label: 'Sent', variant: 'secondary' },
        cancelled: { label: 'Cancelled', variant: 'outline' },
    };
    const s = map[status] ?? { label: status, variant: 'outline' as const };

    return <Badge variant={s.variant}>{s.label}</Badge>;
}
