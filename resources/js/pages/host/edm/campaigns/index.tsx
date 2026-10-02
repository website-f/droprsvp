import { HOST_EDM_CRUMB } from '@/components/edm/ui';
import EdmCampaigns from '@/pages/admin/edm/campaigns/index';

/** The organizer's campaigns: the shared campaigns page, scoped to them. */
export default function HostEdmCampaigns(props: Parameters<typeof EdmCampaigns>[0]) {
    return <EdmCampaigns {...props} />;
}

HostEdmCampaigns.layout = { breadcrumbs: [HOST_EDM_CRUMB, { title: 'Campaigns', href: '/host/edm/campaigns' }] };
