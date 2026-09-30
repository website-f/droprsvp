import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, ImagePlus, Images, Loader2, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { SmartImage } from '@/components/smart-image';
import { Button } from '@/components/ui/button';
import { uploadImageWithToast } from '@/lib/upload';

interface Photo { id: number; path: string; caption: string | null }
interface EventLite { title: string; slug: string }

export default function EventPhotos({ event, photos, galleryOptions = [] }: { event: EventLite; photos: Photo[]; galleryOptions?: string[] }) {
    const confirm = useConfirm();
    const flash = usePage().props.flash as { success?: string } | undefined;
    const fileRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    // Which of the event's promo-gallery images the organizer has ticked to
    // copy across. Kept as a Set of paths, since that is what the server takes.
    const [picked, setPicked] = useState<Set<string>>(new Set());

    const togglePick = (path: string) => setPicked((current) => {
        const next = new Set(current);

        if (next.has(path)) {
            next.delete(path);
        } else {
            next.add(path);
        }

        return next;
    });

    const addFromGallery = () => {
        if (picked.size === 0) {
            return;
        }

        router.post(
            `/host/events/${event.slug}/photos/from-gallery`,
            { paths: [...picked] },
            { preserveScroll: true, onSuccess: () => setPicked(new Set()) },
        );
    };

    const add = async (files: FileList | null) => {
        if (!files || files.length === 0) {
return;
}

        setUploading(true);

        try {
            const results = await Promise.all(Array.from(files).map((f) => uploadImageWithToast(f)));
            const paths = results.filter((p): p is string => p !== null);

            if (paths.length) {
                router.post(`/host/events/${event.slug}/photos`, { paths }, { preserveScroll: true });
            }
        } finally {
            setUploading(false);

            if (fileRef.current) {
fileRef.current.value = '';
}
        }
    };

    const remove = async (photo: Photo) => {
        if (await confirm({ title: 'Remove this photo?', confirmText: 'Remove', destructive: true })) {
            router.delete(`/host/events/${event.slug}/photos/${photo.id}`, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={`Photos · ${event.title}`} />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <Link href="/host/events" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> Back to events</Link>

                <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Event photos</h1>
                        <p className="mt-1 text-sm text-muted-foreground">{event.title} — add photos from the event. These appear on your public organizer profile (separate from the promo gallery).</p>
                    </div>
                    <Button onClick={() => fileRef.current?.click()} disabled={uploading}>
                        {uploading ? <Loader2 className="size-4 animate-spin" /> : <ImagePlus className="size-4" />} Add photos
                    </Button>
                    <input ref={fileRef} type="file" accept="image/*" multiple hidden onChange={(e) => add(e.target.files)} />
                </div>

                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}

                {/* Copy from the event's promo gallery.
                    Only rendered when there is something left to copy, so the
                    section disappears once everything has been added rather
                    than sitting there empty. */}
                {galleryOptions.length > 0 && (
                    <div className="mb-6 rounded-2xl border border-border bg-card p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="flex items-center gap-2 text-sm font-semibold"><Images className="size-4" /> Add from this event's gallery</h2>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Pick promo images to show on your public organizer profile — no need to upload them again.
                                </p>
                            </div>
                            <Button size="sm" onClick={addFromGallery} disabled={picked.size === 0}>
                                Add {picked.size > 0 ? `${picked.size} ` : ''}to profile
                            </Button>
                        </div>
                        <div className="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-5 lg:grid-cols-6">
                            {galleryOptions.map((path) => {
                                const on = picked.has(path);

                                return (
                                    <button
                                        key={path}
                                        type="button"
                                        onClick={() => togglePick(path)}
                                        aria-pressed={on}
                                        className={`relative aspect-square overflow-hidden rounded-lg border-2 transition-colors ${on ? 'border-foreground' : 'border-transparent hover:border-border'}`}
                                    >
                                        <SmartImage src={path} alt="" className="size-full object-cover" />
                                        {on && (
                                            <span className="absolute inset-0 flex items-center justify-center bg-foreground/45">
                                                <span className="flex size-6 items-center justify-center rounded-full bg-background text-foreground"><Check className="size-4" /></span>
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}

                {photos.length === 0 ? (
                    <button type="button" onClick={() => fileRef.current?.click()} className="flex w-full flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-border py-20 text-sm text-muted-foreground transition-colors hover:border-foreground/40 hover:text-foreground">
                        <ImagePlus className="size-8" />
                        {galleryOptions.length > 0 ? 'Upload photos, or pick some from the gallery above' : 'Upload your first event photos'}
                    </button>
                ) : (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        {photos.map((p) => (
                            <div key={p.id} className="group relative aspect-square overflow-hidden rounded-xl border border-border bg-muted">
                                <SmartImage src={p.path} alt={p.caption ?? ''} className="size-full object-cover" />
                                <button type="button" onClick={() => remove(p)} aria-label="Remove" className="absolute right-2 top-2 flex size-8 items-center justify-center rounded-full bg-background/90 text-destructive opacity-0 shadow transition-opacity group-hover:opacity-100">
                                    <Trash2 className="size-4" />
                                </button>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

EventPhotos.layout = { breadcrumbs: [{ title: 'Events', href: '/host/events' }, { title: 'Photos', href: '#' }] };
