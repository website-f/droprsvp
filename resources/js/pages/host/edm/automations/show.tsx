import { HOST_EDM_CRUMB } from '@/components/edm/ui';
import EdmAutomation from '@/pages/admin/edm/automations/show';

/** One of the organizer's sequences, in the shared sequence builder. */
export default function HostEdmAutomation(props: Parameters<typeof EdmAutomation>[0]) {
    return <EdmAutomation {...props} />;
}

HostEdmAutomation.layout = { breadcrumbs: [HOST_EDM_CRUMB, { title: 'Automations', href: '/host/edm/automations' }, { title: 'Sequence', href: '#' }] };
