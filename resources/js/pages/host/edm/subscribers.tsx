import { HOST_EDM_CRUMB } from '@/components/edm/ui';
import EdmSubscribers from '@/pages/admin/edm/subscribers';

/** The organizer's own list: people who opted in to hear from them at checkout. */
export default function HostEdmSubscribers(props: Parameters<typeof EdmSubscribers>[0]) {
    return <EdmSubscribers {...props} />;
}

HostEdmSubscribers.layout = { breadcrumbs: [HOST_EDM_CRUMB, { title: 'Subscribers', href: '/host/edm/subscribers' }] };
