import { Head, useForm } from '@inertiajs/react';
import { ImageUp, Loader2, Store, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { Wordmark } from '@/components/brand';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { uploadImage } from '@/lib/upload';

interface Application {
    business_name: string; website: string | null; phone: string | null; bio: string | null;
    poster: string | null; gallery: string[]; status: string | null; reason: string | null;
}

const field = 'h-11 w-full rounded-xl border border-input bg-card px-3.5 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area = 'w-full rounded-xl border border-input bg-card px-3.5 py-2.5 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function OrganizerApply({ application }: { application: Application }) {
    const form = useForm({
        business_name: application.business_name ?? '',
        phone: application.phone ?? '',
        website: application.website ?? '',
        bio: application.bio ?? '',
        poster: application.poster ?? '',
        gallery: application.gallery ?? [],
    });
    const { data, setData, processing, errors } = form;
    const posterRef = useRef<HTMLInputElement>(null);
    const galleryRef = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState<'poster' | 'gallery' | null>(null);

    const pickPoster = async (file?: File) => {
        if (!file) {
            return;
        }

        setBusy('poster');

        try {
            setData('poster', await uploadImage(file));
        } finally {
            setBusy(null);
        }
    };
    const pickGallery = async (files: FileList | null) => {
        if (!files?.length) {
            return;
        }

        setBusy('gallery');

        try {
            const urls = await Promise.all([...files].slice(0, 8 - data.gallery.length).map((f) => uploadImage(f)));
            setData('gallery', [...data.gallery, ...urls].slice(0, 8));
        } finally {
            setBusy(null);
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/host/apply');
    };

    return (
        <>
            <Head title="Vendor application" />
            <div className="flex min-h-screen flex-col bg-muted/30">
                <header className="flex items-center justify-between px-6 py-5">
                    <Wordmark className="h-8" />
                </header>

                <div className="mx-auto w-full max-w-xl flex-1 px-6 pb-16">
                    <span className="flex size-12 items-center justify-center rounded-2xl bg-foreground text-background"><Store className="size-6" /></span>
                    <h1 className="mt-5 text-2xl font-bold tracking-tight sm:text-3xl">{application.status === 'rejected' ? 'Update your application' : 'Apply to host events'}</h1>
                    <p className="mt-1.5 text-sm text-muted-foreground">Tell us about your business. We review every application and will be in touch by email or phone.</p>

                    {application.status === 'rejected' && application.reason && (
                        <div className="mt-5 rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-sm">
                            <div className="font-medium text-destructive">Your previous application wasn’t approved</div>
                            <p className="mt-1 text-muted-foreground">{application.reason}</p>
                            <p className="mt-1 text-muted-foreground">Update your details below and re-submit.</p>
                        </div>
                    )}

                    <form onSubmit={submit} className="mt-7 grid gap-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="business_name">Business / organizer name</Label>
                            <input id="business_name" className={field} value={data.business_name} onChange={(e) => setData('business_name', e.target.value)} />
                            {errors.business_name && <p className="text-xs text-destructive">{errors.business_name}</p>}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="phone">Contact phone</Label>
                                <input id="phone" className={field} value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="+60 12-345 6789" />
                                {errors.phone && <p className="text-xs text-destructive">{errors.phone}</p>}
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="website">Website (optional)</Label>
                                <input id="website" className={field} value={data.website} onChange={(e) => setData('website', e.target.value)} placeholder="https://…" />
                                {errors.website && <p className="text-xs text-destructive">{errors.website}</p>}
                            </div>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="bio">About your events</Label>
                            <textarea id="bio" rows={3} className={area} value={data.bio} onChange={(e) => setData('bio', e.target.value)} placeholder="What kind of events do you run?" />
                        </div>

                        {/* Poster */}
                        <div className="grid gap-1.5">
                            <Label>Poster (optional)</Label>
                            <input ref={posterRef} type="file" accept="image/*" className="hidden" onChange={(e) => pickPoster(e.target.files?.[0])} />
                            {data.poster ? (
                                <div className="relative w-40 overflow-hidden rounded-xl border border-border">
                                    <img src={data.poster} alt="Poster" className="aspect-[3/4] w-full object-cover" />
                                    <button type="button" onClick={() => setData('poster', '')} className="absolute right-1 top-1 flex size-6 items-center justify-center rounded-full bg-foreground/80 text-background"><X className="size-3.5" /></button>
                                </div>
                            ) : (
                                <Button type="button" variant="outline" size="sm" className="w-max" disabled={busy === 'poster'} onClick={() => posterRef.current?.click()}>
                                    {busy === 'poster' ? <Loader2 className="size-3.5 animate-spin" /> : <ImageUp className="size-3.5" />} Upload poster
                                </Button>
                            )}
                        </div>

                        {/* Gallery */}
                        <div className="grid gap-1.5">
                            <Label>Gallery (optional, up to 8)</Label>
                            <input ref={galleryRef} type="file" accept="image/*" multiple className="hidden" onChange={(e) => pickGallery(e.target.files)} />
                            <div className="flex flex-wrap gap-2">
                                {data.gallery.map((g, i) => (
                                    <div key={i} className="relative size-20 overflow-hidden rounded-lg border border-border">
                                        <img src={g} alt="" className="size-full object-cover" />
                                        <button type="button" onClick={() => setData('gallery', data.gallery.filter((_, idx) => idx !== i))} className="absolute right-0.5 top-0.5 flex size-5 items-center justify-center rounded-full bg-foreground/80 text-background"><X className="size-3" /></button>
                                    </div>
                                ))}
                                {data.gallery.length < 8 && (
                                    <button type="button" onClick={() => galleryRef.current?.click()} disabled={busy === 'gallery'} className="flex size-20 items-center justify-center rounded-lg border border-dashed border-border text-muted-foreground hover:border-foreground/40">
                                        {busy === 'gallery' ? <Loader2 className="size-5 animate-spin" /> : <ImageUp className="size-5" />}
                                    </button>
                                )}
                            </div>
                        </div>

                        <Button type="submit" size="lg" className="mt-2" disabled={processing}>Submit application</Button>
                    </form>
                </div>
            </div>
        </>
    );
}
