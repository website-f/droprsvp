import { Check, Copy, Mail, MoreHorizontal, Share2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { SocialIcon } from '@/components/social-icons';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useClipboard } from '@/hooks/use-clipboard';

/** A phone or tablet, where the OS share sheet is the natural thing (and has Copy). */
const isTouch = () => typeof window !== 'undefined' && window.matchMedia?.('(pointer: coarse)').matches;

/**
 * Hand an organizer their event's registration link.
 *
 * On a phone the OS share sheet opens straight away — an organizer there
 * wants the link in WhatsApp, and the sheet has Copy too. On a computer it
 * opens a menu instead: Copy link first, then the usual apps, then the
 * system sheet as "More options…". The macOS share sheet has no Copy, so
 * an organizer at their desk had no way to simply copy the link.
 *
 * Dismissing a share sheet rejects with AbortError — a choice, not a failure,
 * so it raises no error toast.
 */
export function ShareEventButton({
    url,
    title,
    label = 'Share',
    variant = 'outline',
    unavailableReason,
}: {
    url: string;
    title: string;
    label?: string;
    variant?: 'outline' | 'ghost' | 'default';
    /**
     * Set when the link would not work for whoever received it — a draft, or a
     * private event. The button stays PRESENT and says why instead of
     * disappearing: a control that vanishes silently just reads as a missing
     * feature, which is how this was first reported.
     */
    unavailableReason?: string;
}) {
    const [, copy] = useClipboard();
    const [copied, setCopied] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    const text = `Register for ${title}`;
    const canShare = typeof navigator !== 'undefined' && !!navigator.share;

    useEffect(() => {
        if (!copied) {
            return;
        }

        const t = setTimeout(() => setCopied(false), 2000);

        return () => clearTimeout(t);
    }, [copied]);

    const copyLink = async () => {
        if (await copy(url)) {
            setCopied(true);
            toast.success('Registration link copied');
        } else {
            toast.error('Could not copy the link — you can select it from the event page.');
        }
    };

    const nativeShare = async (): Promise<boolean> => {
        try {
            await navigator.share({ title, text, url });

            return true;
        } catch (e) {
            // Dismissed: done. Anything else: let the caller fall back.
            return e instanceof DOMException && e.name === 'AbortError';
        }
    };

    const onButton = async () => {
        if (unavailableReason) {
            // Deliberately not a `disabled` button: a disabled control cannot be
            // tapped, so on a phone there would be no way to find out why.
            toast.info(unavailableReason);

            return;
        }

        if (isTouch() && canShare && (await nativeShare())) {
            return;
        }

        setMenuOpen(true);
    };

    const open = (href: string) => window.open(href, '_blank', 'noopener,noreferrer');
    const enc = encodeURIComponent;

    return (
        <DropdownMenu open={menuOpen} onOpenChange={setMenuOpen}>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant={variant}
                    size="sm"
                    // The menu is opened by onButton (or not, on a phone); stop
                    // the trigger opening it on its own first.
                    onPointerDown={(e) => e.preventDefault()}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            onButton();
                        }
                    }}
                    onClick={onButton}
                    title={unavailableReason ?? url}
                    className={unavailableReason ? 'text-muted-foreground' : undefined}
                >
                    {copied ? <Check className="size-3.5" /> : <Share2 className="size-3.5" />}
                    <span className="hidden sm:inline">{copied ? 'Copied' : label}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                <DropdownMenuLabel className="font-normal">
                    <div className="text-xs text-muted-foreground">Registration link</div>
                    <div className="mt-0.5 truncate text-xs font-medium" title={url}>{url.replace(/^https?:\/\//, '')}</div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={copyLink}><Copy className="size-4" /> Copy link</DropdownMenuItem>
                <DropdownMenuItem onSelect={() => open(`https://wa.me/?text=${enc(`${text} ${url}`)}`)}><SocialIcon platform="whatsapp" className="size-4" /> WhatsApp</DropdownMenuItem>
                <DropdownMenuItem onSelect={() => open(`https://t.me/share/url?url=${enc(url)}&text=${enc(text)}`)}><SocialIcon platform="telegram" className="size-4" /> Telegram</DropdownMenuItem>
                <DropdownMenuItem onSelect={() => open(`https://www.facebook.com/sharer/sharer.php?u=${enc(url)}`)}><SocialIcon platform="facebook" className="size-4" /> Facebook</DropdownMenuItem>
                <DropdownMenuItem onSelect={() => open(`https://x.com/intent/post?text=${enc(text)}&url=${enc(url)}`)}><SocialIcon platform="x" className="size-4" /> X (Twitter)</DropdownMenuItem>
                <DropdownMenuItem onSelect={() => {
 window.location.href = `mailto:?subject=${enc(title)}&body=${enc(`${text}: ${url}`)}`;
}}><Mail className="size-4" /> Email</DropdownMenuItem>
                {canShare && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={() => {
 nativeShare();
}}><MoreHorizontal className="size-4" /> More options…</DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
