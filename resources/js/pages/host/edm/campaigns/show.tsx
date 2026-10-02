import { HOST_EDM_CRUMB } from '@/components/edm/ui';
import EdmCampaign from '@/pages/admin/edm/campaigns/show';

/** One of the organizer's campaigns: the shared campaign page, with credits and their domains. */
export default function HostEdmCampaign(props: Parameters<typeof EdmCampaign>[0]) {
    return <EdmCampaign {...props} />;
}

HostEdmCampaign.layout = { breadcrumbs: [HOST_EDM_CRUMB, { title: 'Campaigns', href: '/host/edm/campaigns' }, { title: 'Campaign', href: '#' }] };
