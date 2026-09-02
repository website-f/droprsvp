import jsQR from 'jsqr';
import { Camera, Keyboard, X } from 'lucide-react';
import { useEffect, useRef, useState  } from 'react';
import type {ReactNode} from 'react';
import { Switch } from '@/components/ui/switch';

export type ScanTone = 'ok' | 'warn' | 'error' | 'info';
export interface ScanFeedback { tone: ScanTone; title: string; subtitle?: string }

interface Props {
    open: boolean;
    onClose: () => void;
    /** Fired with the decoded string (raw token or full pass URL) each time a code is read. */
    onDecode: (raw: string) => void;
    /** When true the decode loop is idle (e.g. while a manual confirmation is pending). */
    paused?: boolean;
    auto: boolean;
    onAutoChange: (v: boolean) => void;
    feedback?: ScanFeedback | null;
    /** Buttons rendered under the feedback banner (e.g. Check in / Scan next). */
    actions?: ReactNode;
}

const TONE: Record<ScanTone, string> = {
    ok: 'bg-emerald-600 text-white',
    warn: 'bg-amber-500 text-white',
    error: 'bg-red-600 text-white',
    info: 'bg-foreground text-background',
};

/**
 * Fullscreen door scanner. Uses the native BarcodeDetector when available
 * (Chromium/Android) and falls back to jsQR decoding of video frames everywhere
 * else (incl. iOS Safari). Identical codes are ignored for 3s so a badge left in
 * frame isn't read repeatedly. A manual token field covers damaged/unreadable QRs.
 */
export function QrScanner({ open, onClose, onDecode, paused = false, auto, onAutoChange, feedback, actions }: Props) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const [error, setError] = useState<string | null>(null);
    const [manual, setManual] = useState(false);
    const [manualToken, setManualToken] = useState('');

    // Keep the latest callbacks/flags in refs so the camera effect only depends on `open`.
    const onDecodeRef = useRef(onDecode);
    const pausedRef = useRef(paused);
    useEffect(() => {
        onDecodeRef.current = onDecode;
        pausedRef.current = paused;
    });
    const lastRead = useRef<{ raw: string; at: number }>({ raw: '', at: 0 });

    useEffect(() => {
        if (!open) {
            return;
        }

        let stream: MediaStream | undefined;
        let raf = 0;
        let stopped = false;
        const video = videoRef.current;
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d', { willReadFrequently: true });

         
        const BD = (window as any).BarcodeDetector;
        let detector: { detect: (src: CanvasImageSource) => Promise<{ rawValue: string }[]> } | null = null;

        if (BD) {
            try {
                detector = new BD({ formats: ['qr_code'] });
            } catch {
                detector = null;
            }
        }

        const handle = (raw: string) => {
            const now = Date.now();

            // Debounce the same code for 3s; a different code goes through immediately.
            if (raw === lastRead.current.raw && now - lastRead.current.at < 3000) {
                return;
            }

            lastRead.current = { raw, at: now };
            onDecodeRef.current(raw);
        };

        const tick = async () => {
            if (stopped) {
                return;
            }

            const v = videoRef.current;

            if (v && v.readyState >= 2 && !pausedRef.current) {
                try {
                    if (detector) {
                        const codes = await detector.detect(v);

                        if (codes[0]?.rawValue) {
                            handle(codes[0].rawValue);
                        }
                    } else if (ctx) {
                        canvas.width = v.videoWidth;
                        canvas.height = v.videoHeight;
                        ctx.drawImage(v, 0, 0, canvas.width, canvas.height);
                        const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
                        const code = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });

                        if (code?.data) {
                            handle(code.data);
                        }
                    }
                } catch {
                    /* transient decode error — keep scanning */
                }
            }

            raf = requestAnimationFrame(tick);
        };

        (async () => {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });

                if (stopped) {
                    stream.getTracks().forEach((t) => t.stop());

                    return;
                }

                if (video) {
                    video.srcObject = stream;
                    await video.play();
                }

                raf = requestAnimationFrame(tick);
            } catch {
                setError('Camera unavailable — allow camera access, or enter the code manually.');
                setManual(true);
            }
        })();

        return () => {
            stopped = true;
            cancelAnimationFrame(raf);
            stream?.getTracks().forEach((t) => t.stop());
        };
    }, [open]);

    if (!open) {
        return null;
    }

    const submitManual = (e: React.FormEvent) => {
        e.preventDefault();
        const val = manualToken.trim();

        if (val) {
            onDecode(val);
            setManualToken('');
        }
    };

    return (
        <div className="fixed inset-0 z-[60] flex flex-col bg-black">
            {/* Live camera */}
            <video ref={videoRef} playsInline muted className="absolute inset-0 size-full object-cover" />

            {/* Top bar */}
            <div className="relative z-10 flex items-center justify-between gap-3 bg-gradient-to-b from-black/70 to-transparent p-4 text-white">
                <div className="flex items-center gap-2 text-sm font-semibold"><Camera className="size-4" /> Scan tickets</div>
                <label className="flex items-center gap-2 text-xs">
                    Auto check-in
                    <Switch checked={auto} onCheckedChange={onAutoChange} aria-label="Auto check-in on scan" />
                </label>
                <button type="button" onClick={onClose} aria-label="Close scanner" className="flex size-9 items-center justify-center rounded-full bg-white/15 hover:bg-white/25">
                    <X className="size-5" />
                </button>
            </div>

            {/* Reticle */}
            <div className="pointer-events-none relative z-10 flex flex-1 items-center justify-center">
                <div className="size-64 max-w-[70vw] rounded-3xl border-2 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)]" />
            </div>

            {/* Bottom sheet: feedback + actions + manual entry */}
            <div className="relative z-10 space-y-3 bg-gradient-to-t from-black/80 to-transparent p-4 pb-6">
                {feedback && (
                    <div className={`rounded-xl px-4 py-3 text-sm font-medium ${TONE[feedback.tone]}`}>
                        <div className="font-semibold">{feedback.title}</div>
                        {feedback.subtitle && <div className="opacity-90">{feedback.subtitle}</div>}
                    </div>
                )}
                {actions && <div className="flex flex-wrap gap-2">{actions}</div>}

                {manual ? (
                    <form onSubmit={submitManual} className="flex gap-2">
                        <input
                            value={manualToken}
                            onChange={(e) => setManualToken(e.target.value)}
                            placeholder="Enter ticket code"
                            className="h-11 flex-1 rounded-lg border border-white/30 bg-white/10 px-3 text-sm text-white placeholder:text-white/60 outline-none"
                        />
                        <button type="submit" className="h-11 rounded-lg bg-white px-4 text-sm font-semibold text-black">Check</button>
                    </form>
                ) : (
                    <button type="button" onClick={() => setManual(true)} className="flex items-center gap-1.5 text-xs font-medium text-white/80 hover:text-white">
                        <Keyboard className="size-4" /> Enter code manually
                    </button>
                )}
                {error && <p className="text-xs text-amber-300">{error}</p>}
            </div>
        </div>
    );
}
