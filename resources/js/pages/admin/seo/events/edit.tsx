import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, Save } from 'lucide-react';
import { SeoFields } from '@/components/seo-fields';
import type { SeoData } from '@/components/seo-fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface EventInfo { slug: string; title: string; status: string }
interface Props { event: EventInfo; seo: SeoData; fallback: { title: string; description: string }; baseUrl: string }

export default function EventSeoEdit({ event, seo, fallback, baseUrl }: Props) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const form = useForm<{ slug: string; seo: SeoData }>({ slug: event.slug, seo });
    const { data, setData, processing } = form;

    const save = () => form.put(`/admin/seo/events/${event.slug}`, { preserveScroll: true });
    const liveUrl = `${baseUrl}/${data.slug}`;

    return (
        <>
            <Head title={`SEO · ${event.title}`} />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex flex-wrap items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/admin/seo/events" aria-label="Back to events SEO"><ArrowLeft className="size-4" /></Link></Button>
                    <div className="min-w-0">
                        <h1 className="truncate text-xl font-bold tracking-tight">{event.title}</h1>
                        <p className="text-sm text-muted-foreground">Event SEO</p>
                    </div>
                    <Badge variant={event.status === 'published' ? 'default' : 'secondary'} className="ml-auto capitalize">{event.status}</Badge>
                    {event.status === 'published' && (
                        <a href={liveUrl} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 text-sm font-medium underline underline-offset-2"><ExternalLink className="size-3.5" /> View live</a>
                    )}
                </div>

                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}

                <SeoFields
                    seo={data.seo}
                    onChange={(patch) => setData('seo', { ...data.seo, ...patch })}
                    slug={data.slug}
                    onSlug={(v) => setData('slug', v)}
                    fallbackTitle={fallback.title}
                    baseUrl={baseUrl}
                />

                <p className="mt-2 px-1 text-xs text-muted-foreground">
                    Changing the slug changes the event’s URL. Leave the title/description blank to fall back to the event’s own “{fallback.title}”.
                </p>

                <div className="mt-6 flex justify-end">
                    <Button onClick={save} disabled={processing}><Save className="size-4" /> {processing ? 'Saving…' : 'Save SEO'}</Button>
                </div>
            </div>
        </>
    );
}

EventSeoEdit.layout = {
    breadcrumbs: [
        { title: 'Events SEO', href: '/admin/seo/events' },
        { title: 'Edit', href: '#' },
    ],
};
