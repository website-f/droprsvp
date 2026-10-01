import { Framed } from '@/components/smart-image';
import { Button } from '@/components/ui/button';

/**
 * An uploaded event image, previewed the way the public site will show it.
 *
 * The builder used to crop every cover into a 16:9 box and every banner into
 * 3:1. The public site stopped cropping them a while ago — the event page shows
 * the artwork at its own shape, cards letterbox it — so an organizer uploading
 * an Instagram portrait poster saw a sliver of it in the builder and something
 * quite different once published. This shows both views they will get:
 *
 *   * the event page: the whole image at its real shape (capped in height, as
 *     the event page caps it, so a tall story doesn't push the form away);
 *   * an event card: the same framed treatment the home and browse pages use,
 *     when `cardRatio` is given — covers only, since banners are not on cards.
 */
export function ArtworkPreview({
    src,
    busy,
    onReplace,
    onRemove,
    cardRatio,
}: {
    src: string;
    busy?: boolean;
    onReplace: () => void;
    onRemove: () => void;
    /** Tailwind aspect class of the card this image appears on, e.g. "aspect-[16/10]". */
    cardRatio?: string;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_11rem]">
            {/* Event page view: the real shape. */}
            <figure className="grid gap-1.5">
                <div className="relative overflow-hidden rounded-lg border border-border bg-muted/40">
                    <img
                        src={src}
                        alt=""
                        className="mx-auto block h-auto max-h-[28rem] w-auto max-w-full object-contain"
                    />
                    <div className="absolute right-2 top-2 flex gap-2">
                        <Button type="button" size="sm" variant="secondary" disabled={busy} onClick={onReplace}>Replace</Button>
                        <Button type="button" size="sm" variant="secondary" onClick={onRemove}>Remove</Button>
                    </div>
                </div>
                <figcaption className="text-[11px] text-muted-foreground">On the event page — shown whole, at its own shape.</figcaption>
            </figure>

            {/* Card view: what the home and browse pages draw. */}
            {cardRatio && (
                <figure className="grid content-start gap-1.5">
                    <div className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                        <Framed src={src} alt="" ratio={cardRatio} eager />
                        <div className="grid gap-1 p-2.5">
                            <span className="h-2 w-3/4 rounded-full bg-muted" />
                            <span className="h-2 w-1/2 rounded-full bg-muted" />
                        </div>
                    </div>
                    <figcaption className="text-[11px] text-muted-foreground">On event cards — nothing is cropped off.</figcaption>
                </figure>
            )}
        </div>
    );
}
