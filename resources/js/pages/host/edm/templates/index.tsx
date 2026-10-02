import { HOST_EDM_CRUMB } from '@/components/edm/ui';
import EdmTemplates from '@/pages/admin/edm/templates/index';

/** The organizer's template library and the built-in starters. */
export default function HostEdmTemplates(props: Parameters<typeof EdmTemplates>[0]) {
    return <EdmTemplates {...props} />;
}

HostEdmTemplates.layout = { breadcrumbs: [HOST_EDM_CRUMB, { title: 'Templates', href: '/host/edm/templates' }] };
