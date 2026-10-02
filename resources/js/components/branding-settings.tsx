import { useForm } from '@inertiajs/react';
import { Building2, ImagePlus, Loader2, Trash2, UserRound } from 'lucide-react';
import { useRef, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { SocialLinksField } from '@/components/social-links-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { uploadImageWithToast } from '@/lib/upload';

export interface Branding {
    avatar: string | null;
    business_name: string;
    poster: string;
    website: string;
    bio: string;
    /** { platform: link } — only the ones they have. */
    socials?: Record<string, string>;
}

/**
 * Profile photo, and for an organizer their company branding.
 *
 * The logo used to live only on the host APPLICATION form, where re-submitting
 * reopens an approval review — so an approved organizer had nowhere to change it
 * and nobody could find where to upload one. It posts to a separate endpoint
 * that never touches the review cycle.
 */
export function BrandingSettings({ branding, isOrganizer }: { branding: Branding; isOrganizer: boolean }) {
    const form = useForm({
        avatar: branding.avatar ?? '',
        business_name: branding.business_name,
        poster: branding.poster,
        website: branding.website,
        bio: branding.bio,
        socials: { ...(branding.socials ?? {}) } as Record<string, string>,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/branding', { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <Heading
                variant="small"
                title={isOrganizer ? 'Photo & company branding' : 'Profile photo'}
                description={
                    isOrganizer
                        ? 'Your company logo is what attendees see on your public organizer page and next to your events.'
                        : 'Shown on your profile and next to anything you post.'
                }
            />

            <ImageField
                label="Profile photo"
                hint="A square image works best."
                value={form.data.avatar}
                onChange={(v) => form.setData('avatar', v)}
                fallback={<UserRound className="size-6 text-muted-foreground" />}
                round
            />
            <InputError message={form.errors.avatar} />

            {isOrganizer && (
                <>
                    <ImageField
                        label="Company logo"
                        hint="Used on your public organizer page. Takes priority over your profile photo."
                        value={form.data.poster}
                        onChange={(v) => form.setData('poster', v)}
                        fallback={<Building2 className="size-6 text-muted-foreground" />}
                    />
                    <InputError message={form.errors.poster} />

                    <div className="grid gap-2">
                        <Label htmlFor="business_name">Business name</Label>
                        <Input
                            id="business_name"
                            value={form.data.business_name}
                            onChange={(e) => form.setData('business_name', e.target.value)}
                            placeholder="e.g. BoardLah Entertainment"
                        />
                        <p className="text-xs text-muted-foreground">Shown instead of your own name on your organizer page.</p>
                        <InputError message={form.errors.business_name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="website">
                            Website <span className="font-normal text-muted-foreground">(optional)</span>
                        </Label>
                        {/* Not type="url": the browser's own check rejects
                            "instagram.com/3dexpress" for want of a scheme and
                            blocks the submit before the server ever sees it.
                            The server adds the https:// and rejects anything
                            that is not a web address. */}
                        <Input
                            id="website"
                            type="text"
                            inputMode="url"
                            autoComplete="url"
                            value={form.data.website}
                            onChange={(e) => form.setData('website', e.target.value)}
                            placeholder="yourbrand.com"
                        />
                        <InputError message={form.errors.website} />
                    </div>

                    <div className="grid gap-2">
                        <Label>
                            Social media <span className="font-normal text-muted-foreground">(optional)</span>
                        </Label>
                        <SocialLinksField
                            value={form.data.socials}
                            onChange={(next) => form.setData('socials', next)}
                            errors={form.errors as Record<string, string | undefined>}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="bio">About</Label>
                        <textarea
                            id="bio"
                            rows={4}
                            value={form.data.bio}
                            onChange={(e) => form.setData('bio', e.target.value)}
                            className="w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                            placeholder="What kind of events do you run?"
                        />
                        <InputError message={form.errors.bio} />
                    </div>

                    <p className="text-xs text-muted-foreground">
                        Changing these does not affect your approval status.
                    </p>
                </>
            )}

            <Button type="submit" disabled={form.processing}>
                {form.processing && <Loader2 className="size-4 animate-spin" />} Save
            </Button>
        </form>
    );
}

/** Upload-or-remove control for a single image URL. */
function ImageField({
    label,
    hint,
    value,
    onChange,
    fallback,
    round = false,
}: {
    label: string;
    hint: string;
    value: string;
    onChange: (value: string) => void;
    fallback: React.ReactNode;
    round?: boolean;
}) {
    const input = useRef<HTMLInputElement | null>(null);
    const [uploading, setUploading] = useState(false);

    const pick = async (file: File | undefined) => {
        if (!file) {
            return;
        }

        setUploading(true);
        const url = await uploadImageWithToast(file);

        if (url) {
            onChange(url);
        }

        setUploading(false);
    };

    return (
        <div className="grid gap-2">
            <Label>{label}</Label>
            <div className="flex items-center gap-4">
                <div
                    className={`grid size-16 shrink-0 place-items-center overflow-hidden border border-border bg-muted/40 ${round ? 'rounded-full' : 'rounded-xl'}`}
                >
                    {uploading ? <Loader2 className="size-4 animate-spin text-muted-foreground" /> : value ? <img src={value} alt="" className="size-full object-cover" /> : fallback}
                </div>

                <input ref={input} type="file" accept="image/*" className="hidden" onChange={(e) => pick(e.target.files?.[0])} />

                <div className="flex flex-wrap gap-2">
                    <Button type="button" variant="outline" size="sm" disabled={uploading} onClick={() => input.current?.click()}>
                        <ImagePlus className="size-3.5" /> {value ? 'Replace' : 'Upload'}
                    </Button>
                    {value && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={() => onChange('')}
                        >
                            <Trash2 className="size-3.5" /> Remove
                        </Button>
                    )}
                </div>
            </div>
            <p className="text-xs text-muted-foreground">{hint}</p>
        </div>
    );
}
