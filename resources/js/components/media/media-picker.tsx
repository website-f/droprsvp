import { MediaBrowser } from '@/components/media/media-browser';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { MediaItem } from '@/lib/upload';

/**
 * "Choose an image" — the Media Library in a dialog, for the page and email
 * builders and the HTML editors. Pick an existing image or upload a new one
 * (it lands in the library either way); double-click picks straight away.
 */
export function MediaPicker({ open, onOpenChange, onPick, title = 'Choose an image', pickLabel }: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onPick: (item: MediaItem) => void;
    title?: string;
    pickLabel?: string;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex h-[85vh] w-[calc(100vw-2rem)] max-w-5xl flex-col gap-3 sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>Pick from the Media Library, or upload a new image — it is saved there for next time.</DialogDescription>
                </DialogHeader>
                {/* Only mounted while open, so the library is fetched when needed. */}
                {open && (
                    <MediaBrowser
                        className="min-h-0 flex-1"
                        pickLabel={pickLabel}
                        onPick={(item) => {
                            onPick(item);
                            onOpenChange(false);
                        }}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
