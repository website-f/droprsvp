import { Render } from '@measured/puck';
import type { Data } from '@measured/puck';
import { renderConfig } from '@/components/cms/puck-render';
import type { PostCard } from '@/components/cms/puck-render';

/**
 * The read-only page preview used on the page form. Split into its own module so
 * the heavy Puck runtime + widget config only load when there's actually a built
 * page to show — a brand-new page never pulls this chunk, so the form opens fast.
 * Uses the render-only config, so none of the editor field code loads here.
 */
export default function PagePreview({ data, posts }: { data: Data; posts: PostCard[] }) {
    return <Render config={renderConfig} data={data} metadata={{ posts }} />;
}
