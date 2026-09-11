import { Head, useForm, usePage } from '@inertiajs/react';
import { ImageIcon, Loader2, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { TagInput } from '@/components/ui/tag-input';
import { uploadImageWithToast } from '@/lib/upload';

interface HomeSeo { title: string; description: string; keywords: string; image: string }

const field = 'h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area = 'w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

function Count({ value, max }: { value: string; max: number }) {
    const len = value.length;

    return <span className={`text-xs ${len > max ? 'text-destructive' : 'text-muted-foreground'}`}>{len}/{max}</span>;
}

export default function HomeSeoSettings({ seo, defaultImage }: { seo: HomeSeo; defaultImage: string }) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const [baseUrl] = useState(() => (typeof window !== 'undefined' ? window.location.origin : ''));
    const fileRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);

    const form = useForm<HomeSeo>({ title: seo.title ?? '', description: seo.description ?? '', keywords: seo.keywords ?? '', image: seo.image ?? '' });
    const { data, setData, processing } = form;

    // What crawlers will actually use: the custom image, else the site default.
    const shareImage = data.image || defaultImage;

    const pickImage = async (file: File | undefined) => {
        if (!file) {
            return;
        }

        setUploading(true);
        const url = await uploadImageWithToast(file);

        setUploading(false);

        if (url) {
            setData('image', url);
        }
    };

    const save = () => form.post('/admin/site/home-seo', { preserveScroll: true });

    return (
        <>
            <Head title="Homepage SEO" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Homepage SEO</h1>
                        <p className="text-sm text-muted-foreground">The landing page design is fixed — here you tune how it appears in search &amp; social.</p>
                    </div>
                    <Button onClick={save} disabled={processing}>Save changes</Button>
                </div>
                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}

                <section className="rounded-xl border border-border bg-card p-5 shadow-sm">
                    {/* Google snippet preview */}
                    <div className="mb-5 rounded-lg border border-border bg-muted/40 p-4">
                        <div className="truncate text-xs text-emerald-700 dark:text-emerald-400">{baseUrl || 'https://example.com'}</div>
                        <div className="mt-0.5 truncate text-base text-blue-700 dark:text-blue-400">{data.title || 'Your homepage title'}</div>
                        <div className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{data.description || 'Add a description to control how the homepage appears in search results.'}</div>
                    </div>

                    <div className="grid gap-4">
                        <div className="grid gap-1.5">
                            <div className="flex items-center justify-between"><Label>Title</Label><Count value={data.title} max={60} /></div>
                            <input className={field} value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="DropRSVP — Discover events near you" />
                        </div>
                        <div className="grid gap-1.5">
                            <div className="flex items-center justify-between"><Label>Description</Label><Count value={data.description} max={155} /></div>
                            <textarea rows={3} className={area} value={data.description} onChange={(e) => setData('description', e.target.value)} placeholder="Find events happening near you and get tickets…" />
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Keywords</Label>
                            <TagInput value={data.keywords} onChange={(v) => setData('keywords', v)} placeholder="Type a keyword, press Enter…" />
                            <p className="text-xs text-muted-foreground">Type each keyword and press Enter. Rendered as the <code>meta keywords</code> tag. Leave blank to use the default.</p>
                        </div>
                    </div>
                </section>

                {/* Social share image (og:image / twitter:image) */}
                <section className="mt-5 rounded-xl border border-border bg-card p-5 shadow-sm">
                    <h2 className="flex items-center gap-2 text-sm font-semibold"><ImageIcon className="size-4" /> Share image</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        The picture shown when the homepage is shared on WhatsApp, Facebook, X or LinkedIn. Use a 1200&times;630 image — anything else gets cropped. Leave it empty to use the site default.
                    </p>

                    <div className="mt-4 grid gap-5 lg:grid-cols-2">
                        {/* Live preview — exactly what the link unfurls to. */}
                        <div>
                            <div className="mb-2 text-xs font-medium text-muted-foreground">Preview</div>
                            <div className="overflow-hidden rounded-xl border border-border bg-background">
                                <div className="aspect-[1200/630] w-full bg-muted">
                                    {shareImage
                                        ? <img key={shareImage} src={shareImage} alt="" className="size-full object-cover" />
                                        : <div className="flex size-full items-center justify-center text-xs text-muted-foreground">No image</div>}
                                </div>
                                <div className="border-t border-border px-3 py-2.5">
                                    <div className="truncate text-[11px] uppercase tracking-wide text-muted-foreground">{(baseUrl || 'https://example.com').replace(/^https?:\/\//, '')}</div>
                                    <div className="mt-0.5 truncate text-sm font-semibold">{data.title || 'Your homepage title'}</div>
                                    <div className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{data.description || 'Add a description above.'}</div>
                                </div>
                            </div>
                            {!data.image && <p className="mt-2 text-xs text-muted-foreground">Currently using the site default image.</p>}
                        </div>

                        <div className="grid content-start gap-3">
                            <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={(e) => {
                                void pickImage(e.target.files?.[0]);
                                e.target.value = '';
                            }} />
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" variant="outline" onClick={() => fileRef.current?.click()} disabled={uploading}>
                                    {uploading ? <><Loader2 className="size-4 animate-spin" /> Uploading…</> : <><Upload className="size-4" /> {data.image ? 'Replace image' : 'Upload image'}</>}
                                </Button>
                                {data.image && (
                                    <Button type="button" variant="ghost" className="text-muted-foreground" onClick={() => setData('image', '')}>
                                        <Trash2 className="size-4" /> Use default
                                    </Button>
                                )}
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="og-url">Or paste an image URL</Label>
                                <input id="og-url" className={field} value={data.image} onChange={(e) => setData('image', e.target.value)} placeholder="/storage/uploads/share.png" />
                                <p className="text-xs text-muted-foreground">Changes appear in the preview immediately, and go live on the homepage once you press <strong>Save changes</strong>.</p>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}
