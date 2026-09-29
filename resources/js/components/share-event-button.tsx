import { Check, Share2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';

/**
 * Hand an organizer their event's registration link.
 *
 * Uses the OS share sheet where there is one, because an organizer on a phone
 * wants the link in WhatsApp, not on a clipboard they then have to paste. Falls
 * back to copying everywhere else, and also if the share sheet is dismissed —
 * navigator.share rejects with AbortError on cancel, which is not a failure and
 * must not raise an error toast.
 */
export function ShareEventButton({
    url,
    title,
    label = 'Share',
    variant = 'outline',
}: {
    url: string;
    title: string;
    label?: string;
    variant?: 'outline' | 'ghost' | 'default';
}) {
    const [, copy] = useClipboard();
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }
        const t = setTimeout(() => setCopied(false), 2000);

        return () => clearTimeout(t);
    }, [copied]);

    const share = async () => {
        if (typeof navigator !== 'undefined' && navigator.share) {
            try {
                await navigator.share({ title, text: `Register for ${title}`, url });

                return;
            } catch (e) {
                // Dismissing the sheet is a choice, not an error — fall through
                // to copying only when the share itself was unavailable.
                if (e instanceof DOMException && e.name === 'AbortError') {
                    return;
                }
            }
        }

        if (await copy(url)) {
            setCopied(true);
            toast.success('Registration link copied');
        } else {
            toast.error('Could not copy the link — you can select it from the event page.');
        }
    };

    return (
        <Button type="button" variant={variant} size="sm" onClick={share} title={url}>
            {copied ? <Check className="size-3.5" /> : <Share2 className="size-3.5" />}
            <span className="hidden sm:inline">{copied ? 'Copied' : label}</span>
        </Button>
    );
}
