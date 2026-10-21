import { Head } from '@inertiajs/react';
import { MediaBrowser } from '@/components/media/media-browser';

/**
 * Content → Media: every image uploaded to DropRSVP in one place, to upload,
 * search, rename, describe and delete — and to copy a URL from for anything
 * written by hand. The page and email builders pick from the same library.
 */
export default function MediaLibrary() {
    return (
        <>
            <Head title="Media Library" />
            <div className="flex h-[calc(100vh-4rem)] w-full flex-1 flex-col p-4">
                <div className="mb-4">
                    <h1 className="text-2xl font-bold tracking-tight">Media Library</h1>
                    <p className="text-sm text-muted-foreground">Upload once, use anywhere — pages, posts, emails. Drop images onto the grid to upload them.</p>
                </div>
                <MediaBrowser manage className="min-h-[60vh] flex-1" />
            </div>
        </>
    );
}

MediaLibrary.layout = {
    breadcrumbs: [{ title: 'Media Library', href: '/admin/cms/media' }],
};
