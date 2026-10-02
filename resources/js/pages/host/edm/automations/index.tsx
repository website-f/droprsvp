import { HOST_EDM_CRUMB } from '@/components/edm/ui';
import EdmAutomations from '@/pages/admin/edm/automations/index';

/** The organizer's automations: reminders, follow-ups and recovery for their events. */
export default function HostEdmAutomations(props: Parameters<typeof EdmAutomations>[0]) {
    return <EdmAutomations {...props} />;
}

HostEdmAutomations.layout = { breadcrumbs: [HOST_EDM_CRUMB, { title: 'Automations', href: '/host/edm/automations' }] };
