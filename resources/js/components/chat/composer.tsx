import { ImagePlus, SendHorizontal, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * The message box: grows with the text, Enter sends (Shift+Enter for a new
 * line; on phones the return key adds a line and the button sends), images by
 * button, paste or drag-and-drop, with a preview before sending.
 */
export function Composer({ disabled, disabledReason, imagesAllowed, maxLength, maxImageMb, onSend, onTyping }: {
    disabled: boolean;
    disabledReason?: string | null;
    imagesAllowed: boolean;
    maxLength: number;
    maxImageMb: number;
    onSend: (body: string, image: File | null) => void;
    onTyping: () => void;
}) {
    const [body, setBody] = useState('');
    const [image, setImage] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);
    const area = useRef<HTMLTextAreaElement>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const coarse = typeof window !== 'undefined' && window.matchMedia?.('(pointer: coarse)').matches;

    useEffect(() => {
        if (!image) {
            return;
        }

        const url = URL.createObjectURL(image);
        // Assigned in the effect that owns the object URL, so it is always revoked.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [image]);

    // Grow to fit, up to about six lines.
    useEffect(() => {
        const el = area.current;

        if (el) {
            el.style.height = 'auto';
            el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
        }
    }, [body]);

    const pick = (file: File | null | undefined) => {
        setError(null);

        if (!file) {
            return;
        }

        if (!imagesAllowed) {
            setError('Images can’t be sent here.');

            return;
        }

        if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type)) {
            setError('JPG, PNG, WebP or GIF only.');

            return;
        }

        if (file.size > maxImageMb * 1024 * 1024) {
            setError(`Images can be up to ${maxImageMb} MB.`);

            return;
        }

        setImage(file);
    };

    const clearImage = () => {
        setImage(null);
        setPreview(null);
    };

    const submit = () => {
        const text = body.trim();

        if (disabled || (!text && !image) || text.length > maxLength) {
            return;
        }

        onSend(text, image);
        setBody('');
        clearImage();
        area.current?.focus();
    };

    if (disabled) {
        return (
            <div className="border-t border-border bg-card px-4 py-3 text-center text-sm text-muted-foreground">
                {disabledReason ?? 'You can’t reply to this conversation.'}
            </div>
        );
    }

    return (
        <div
            className={`border-t border-border bg-card px-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] pt-2 sm:px-3 ${dragging ? 'ring-2 ring-inset ring-primary' : ''}`}
            onDragOver={(e) => {
                if (imagesAllowed && e.dataTransfer.types.includes('Files')) {
                    e.preventDefault();
                    setDragging(true);
                }
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={(e) => {
                e.preventDefault();
                setDragging(false);
                pick(e.dataTransfer.files?.[0]);
            }}
        >
            {preview && (
                <div className="mb-2 flex items-start gap-2 px-1">
                    <div className="relative">
                        <img src={preview} alt="To send" className="h-20 w-20 rounded-xl object-cover" />
                        <button type="button" onClick={clearImage} className="absolute -right-2 -top-2 rounded-full bg-foreground p-0.5 text-background shadow" aria-label="Remove image"><X className="size-3.5" /></button>
                    </div>
                </div>
            )}
            {error && <p className="mb-1 px-2 text-xs text-destructive">{error}</p>}

            <div className="flex items-end gap-1.5">
                {imagesAllowed && (
                    <>
                        <button type="button" onClick={() => fileInput.current?.click()} className="flex size-10 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground" aria-label="Add an image">
                            <ImagePlus className="size-5" />
                        </button>
                        <input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp,image/gif" className="hidden" onChange={(e) => {
                            pick(e.target.files?.[0]);
                            e.target.value = '';
                        }} />
                    </>
                )}

                <div className="relative min-w-0 flex-1">
                    <textarea
                        ref={area}
                        rows={1}
                        value={body}
                        maxLength={maxLength + 50}
                        placeholder="Message…"
                        onChange={(e) => {
                            setBody(e.target.value);
                            onTyping();
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey && !coarse && !e.nativeEvent.isComposing) {
                                e.preventDefault();
                                submit();
                            }
                        }}
                        onPaste={(e) => {
                            const file = Array.from(e.clipboardData.files)[0];

                            if (file) {
                                e.preventDefault();
                                pick(file);
                            }
                        }}
                        className="block max-h-40 min-h-10 w-full resize-none rounded-2xl border border-input bg-muted/40 px-4 py-2.5 text-[16px] leading-snug outline-none transition-colors focus:border-ring focus:bg-card sm:text-sm"
                    />
                    {body.length > maxLength * 0.9 && (
                        <span className={`absolute bottom-1 right-3 text-[10px] ${body.length > maxLength ? 'text-destructive' : 'text-muted-foreground'}`}>{body.length}/{maxLength}</span>
                    )}
                </div>

                <button
                    type="button"
                    onClick={submit}
                    disabled={(!body.trim() && !image) || body.length > maxLength}
                    className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity disabled:opacity-40"
                    aria-label="Send"
                >
                    <SendHorizontal className="size-5" />
                </button>
            </div>
        </div>
    );
}
