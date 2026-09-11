import { Head, useForm, usePage } from '@inertiajs/react';
import { ImageIcon, Loader2, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { uploadImageWithToast } from '@/lib/upload';

interface BlogAd {
    enabled: boolean;
    title: string;
    image: string;
    url: string;
    caption: string;
}

const field =
    'h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area =
    'w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function BlogSidebarSettings({ ad }: { ad: BlogAd }) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const fileRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);

    const form = useForm<BlogAd>({
        enabled: ad.enabled,
        title: ad.title ?? '',
        image: ad.image ?? '',
        url: ad.url ?? '',
        caption: ad.caption ?? '',
    });
    const { data, setData, processing } = form;

    const pick = async (file: File | undefined) => {
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

    return (
        <>
            <Head title="Blog sidebar" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Blog sidebar
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            The promo slot shown beside posts. Categories and
                            recent posts fill themselves in.
                        </p>
                    </div>
                    <Button
                        onClick={() =>
                            form.post('/admin/site/blog', {
                                preserveScroll: true,
                            })
                        }
                        disabled={processing}
                    >
                        Save changes
                    </Button>
                </div>
                {flash?.success && (
                    <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">
                        {flash.success}
                    </div>
                )}

                <section className="rounded-xl border border-border bg-card p-5 shadow-sm">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="flex items-center gap-2 text-sm font-semibold">
                                <ImageIcon className="size-4" /> Promo slot
                            </h2>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                Switched off — or with no image — the rail
                                simply closes up.
                            </p>
                        </div>
                        <Switch
                            checked={data.enabled}
                            onCheckedChange={(v) => setData('enabled', v)}
                            aria-label="Show the promo slot"
                        />
                    </div>

                    <div
                        className={`mt-5 grid gap-5 transition-opacity lg:grid-cols-2 ${data.enabled ? '' : 'pointer-events-none opacity-50'}`}
                    >
                        {/* Preview at the real rail width so the crop is honest. */}
                        <div>
                            <div className="mb-2 text-xs font-medium text-muted-foreground">
                                Preview
                            </div>
                            <div className="max-w-[19rem] rounded-2xl border border-border bg-background p-5">
                                {data.title && (
                                    <div className="mb-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        {data.title}
                                    </div>
                                )}
                                {data.image ? (
                                    <img
                                        src={data.image}
                                        alt=""
                                        className="w-full rounded-xl border border-border object-cover"
                                    />
                                ) : (
                                    <div className="flex aspect-[4/5] w-full items-center justify-center rounded-xl border border-dashed border-border text-xs text-muted-foreground">
                                        No image yet
                                    </div>
                                )}
                                {data.caption && (
                                    <p className="mt-3 text-xs leading-relaxed text-muted-foreground">
                                        {data.caption}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="grid content-start gap-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="ad-title">Heading</Label>
                                <input
                                    id="ad-title"
                                    className={field}
                                    value={data.title}
                                    onChange={(e) =>
                                        setData('title', e.target.value)
                                    }
                                    placeholder="Advertise with us"
                                />
                            </div>

                            <input
                                ref={fileRef}
                                type="file"
                                accept="image/*"
                                className="hidden"
                                onChange={(e) => {
                                    void pick(e.target.files?.[0]);
                                    e.target.value = '';
                                }}
                            />
                            <div className="grid gap-1.5">
                                <Label>Image</Label>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => fileRef.current?.click()}
                                        disabled={uploading}
                                    >
                                        {uploading ? (
                                            <>
                                                <Loader2 className="size-4 animate-spin" />{' '}
                                                Uploading…
                                            </>
                                        ) : (
                                            <>
                                                <Upload className="size-4" />{' '}
                                                {data.image
                                                    ? 'Replace'
                                                    : 'Upload'}
                                            </>
                                        )}
                                    </Button>
                                    {data.image && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="text-muted-foreground"
                                            onClick={() => setData('image', '')}
                                        >
                                            <Trash2 className="size-4" /> Remove
                                        </Button>
                                    )}
                                </div>
                                <input
                                    className={field}
                                    value={data.image}
                                    onChange={(e) =>
                                        setData('image', e.target.value)
                                    }
                                    placeholder="…or paste an image URL"
                                />
                                <p className="text-xs text-muted-foreground">
                                    A tall image (about 4:5) fits the rail best.
                                </p>
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="ad-url">Link</Label>
                                <input
                                    id="ad-url"
                                    className={field}
                                    value={data.url}
                                    onChange={(e) =>
                                        setData('url', e.target.value)
                                    }
                                    placeholder="https://example.com/offer"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Opens in a new tab, tagged{' '}
                                    <code>rel=&quot;sponsored&quot;</code> so it
                                    never affects your SEO.
                                </p>
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="ad-caption">Caption</Label>
                                <textarea
                                    id="ad-caption"
                                    rows={2}
                                    className={area}
                                    value={data.caption}
                                    onChange={(e) =>
                                        setData('caption', e.target.value)
                                    }
                                    placeholder="Reach thousands of event-goers each month."
                                />
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}

BlogSidebarSettings.layout = {
    breadcrumbs: [{ title: 'Blog sidebar', href: '/admin/site/blog' }],
};
