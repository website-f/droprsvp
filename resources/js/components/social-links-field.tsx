import { Check, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { SocialIcon, platformLabel } from '@/components/social-icons';
import { cn } from '@/lib/utils';

/** Platforms an organizer can link, in display order. Mirrors App\Support\SocialLinks::PLATFORMS. */
export const ORGANIZER_SOCIALS: { value: string; placeholder: string }[] = [
    { value: 'instagram', placeholder: 'instagram.com/yourbrand or @yourbrand' },
    { value: 'facebook', placeholder: 'facebook.com/yourpage' },
    { value: 'tiktok', placeholder: 'tiktok.com/@yourbrand or @yourbrand' },
    { value: 'threads', placeholder: 'threads.net/@yourbrand or @yourbrand' },
    { value: 'x', placeholder: 'x.com/yourbrand or @yourbrand' },
    { value: 'youtube', placeholder: 'youtube.com/@yourchannel' },
    { value: 'linkedin', placeholder: 'linkedin.com/company/yourbrand' },
    { value: 'whatsapp', placeholder: 'wa.me/60123456789, or just your number' },
    { value: 'telegram', placeholder: 't.me/yourchannel or @yourchannel' },
    { value: 'pinterest', placeholder: 'pinterest.com/yourbrand' },
];

/**
 * Social profiles as a row of logos rather than ten empty boxes.
 *
 * Organizers have two or three of these, not ten, so the field shows every
 * platform as a logo and only opens an input for the one they tap. Filled
 * platforms stay highlighted; a platform the server rejected is marked red and
 * reopens with the reason.
 */
export function SocialLinksField({
    value,
    onChange,
    errors = {},
}: {
    value: Record<string, string>;
    onChange: (next: Record<string, string>) => void;
    /** Laravel's errors, keyed "socials.instagram". */
    errors?: Record<string, string | undefined>;
}) {
    const firstError = ORGANIZER_SOCIALS.find((p) => errors[`socials.${p.value}`])?.value ?? null;
    const [open, setOpen] = useState<string | null>(firstError);
    const inputRef = useRef<HTMLInputElement>(null);

    const filled = ORGANIZER_SOCIALS.filter((p) => (value[p.value] ?? '').trim() !== '').length;
    const active = ORGANIZER_SOCIALS.find((p) => p.value === open);

    const set = (platform: string, url: string) => onChange({ ...value, [platform]: url });

    const toggle = (platform: string) => {
        setOpen((current) => (current === platform ? null : platform));
        // After the input renders.
        requestAnimationFrame(() => inputRef.current?.focus());
    };

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap gap-2" role="group" aria-label="Social profiles">
                {ORGANIZER_SOCIALS.map((p) => {
                    const has = (value[p.value] ?? '').trim() !== '';
                    const bad = !!errors[`socials.${p.value}`];
                    const isOpen = open === p.value;

                    return (
                        <button
                            key={p.value}
                            type="button"
                            onClick={() => toggle(p.value)}
                            aria-pressed={isOpen}
                            aria-label={`${platformLabel(p.value)}${has ? ' (added)' : ''}`}
                            title={platformLabel(p.value)}
                            className={cn(
                                'relative flex size-10 items-center justify-center rounded-full border transition-colors',
                                has ? 'border-foreground bg-foreground text-background' : 'border-border text-muted-foreground hover:border-foreground/40 hover:text-foreground',
                                isOpen && 'ring-2 ring-ring ring-offset-2 ring-offset-background',
                                bad && 'border-destructive text-destructive',
                            )}
                        >
                            <SocialIcon platform={p.value} className="size-4" />
                            {has && !bad && (
                                <span className="absolute -right-0.5 -top-0.5 flex size-4 items-center justify-center rounded-full bg-emerald-500 text-white">
                                    <Check className="size-2.5" strokeWidth={3} />
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>

            {active ? (
                <div className="grid gap-1.5 rounded-xl border border-border bg-muted/30 p-3">
                    <label htmlFor={`social-${active.value}`} className="flex items-center gap-2 text-sm font-medium">
                        <SocialIcon platform={active.value} className="size-4" /> {platformLabel(active.value)} profile
                    </label>
                    <div className="flex gap-2">
                        <input
                            ref={inputRef}
                            id={`social-${active.value}`}
                            inputMode="url"
                            autoComplete="off"
                            value={value[active.value] ?? ''}
                            onChange={(e) => set(active.value, e.target.value)}
                            onKeyDown={(e) => {
                                // Enter closes this one instead of submitting the whole form.
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    setOpen(null);
                                }
                            }}
                            placeholder={active.placeholder}
                            className="h-9 min-w-0 flex-1 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                        />
                        {(value[active.value] ?? '') !== '' && (
                            <button
                                type="button"
                                onClick={() => {
                                    set(active.value, '');
                                    setOpen(null);
                                }}
                                className="flex h-9 items-center gap-1 rounded-md px-2 text-xs text-muted-foreground hover:text-destructive"
                            >
                                <X className="size-3.5" /> Remove
                            </button>
                        )}
                        <button type="button" onClick={() => setOpen(null)} className="h-9 rounded-md border border-border px-3 text-xs font-medium hover:bg-accent">
                            Done
                        </button>
                    </div>
                    {errors[`socials.${active.value}`] && <p className="text-xs text-destructive">{errors[`socials.${active.value}`]}</p>}
                </div>
            ) : (
                <p className="text-xs text-muted-foreground">
                    {filled === 0 ? 'Tap a logo to add your profile link.' : `${filled} added — shown as logos on your organizer page. Tap one to edit it.`}
                </p>
            )}
        </div>
    );
}
