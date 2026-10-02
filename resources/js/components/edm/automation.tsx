import { BellRing, CalendarCheck, HeartHandshake, ShoppingCart } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { StatusPill } from '@/components/edm/ui';

/** Shared by the automations list and the sequence builder. */

export const TRIGGER_ICON: Record<string, LucideIcon> = {
    event_reminder: BellRing,
    post_event: HeartHandshake,
    abandoned_checkout: ShoppingCart,
    welcome: CalendarCheck,
};

export const TRIGGER_TINT: Record<string, string> = {
    event_reminder: '#6c63ff',
    post_event: '#22c55e',
    abandoned_checkout: '#f97316',
    welcome: '#3b82f6',
};

export function automationStatus(status: string) {
    return <StatusPill status={status === 'active' ? 'pass' : status === 'paused' ? 'warn' : 'unknown'} label={status === 'active' ? 'On' : status === 'paused' ? 'Paused' : 'Draft'} />;
}
