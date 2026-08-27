import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Camera, CheckCircle2, ScanLine, XCircle } from 'lucide-react';

interface ScanResult { ok: boolean; already?: boolean; name?: string | null; message: string }
interface Props {
    event: { title: string; slug: string };
    stats: { total: number; checked_in: number };
    recent: Array<{ name: string; at: string | null }>;
    scan?: ScanResult;
}

const field = 'h-12 w-full rounded-lg border border-input bg-card px-4 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function CheckIn({ event, stats, recent, scan }: Props) {
    const [token, setToken] = useState('');
    const [busy, setBusy] = useState(false);
    const [camera, setCamera] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const videoRef = useRef<HTMLVideoElement>(null);

    const check = (raw: string) => {
        const val = raw.trim();
        if (!val || busy) return;
        setBusy(true);
        router.post(`/host/events/${event.slug}/checkin`, { token: val }, {
            preserveScroll: true,
            onFinish: () => { setBusy(false); setToken(''); inputRef.current?.focus(); },
        });
    };

    // Keep the field focused so USB/Bluetooth scanners (which type + Enter) just work.
    useEffect(() => { inputRef.current?.focus(); }, [scan]);

    // Progressive camera scanning via the native BarcodeDetector (Chromium/Android).
    const cameraSupported = typeof window !== 'undefined' && 'BarcodeDetector' in window && !!navigator.mediaDevices;
    useEffect(() => {
        if (!camera) return;
        let stream: MediaStream | undefined;
        let raf = 0;
        let stopped = false;
        (async () => {
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const detector = new (window as any).BarcodeDetector({ formats: ['qr_code'] });
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            if (videoRef.current) { videoRef.current.srcObject = stream; await videoRef.current.play(); }
            const tick = async () => {
                if (stopped || !videoRef.current) return;
                try {
                    const codes = await detector.detect(videoRef.current);
                    if (codes.length) { setCamera(false); check(codes[0].rawValue); return; }
                } catch { /* keep trying */ }
                raf = requestAnimationFrame(tick);
            };
            raf = requestAnimationFrame(tick);
        })().catch(() => setCamera(false));
        return () => { stopped = true; cancelAnimationFrame(raf); stream?.getTracks().forEach((t) => t.stop()); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [camera]);

    const pct = stats.total ? Math.round((stats.checked_in / stats.total) * 100) : 0;

    return (
        <>
            <Head title={`Check-in · ${event.title}`} />
            <div className="mx-auto w-full max-w-xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/host/events"><ArrowLeft className="size-4" /></Link></Button>
                    <div>
                        <h1 className="text-xl font-bold tracking-tight">Check-in</h1>
                        <p className="text-sm text-muted-foreground">{event.title}</p>
                    </div>
                </div>

                {/* Counter */}
                <div className="mb-4 rounded-2xl border border-border bg-card p-5 text-center shadow-sm">
                    <div className="text-4xl font-bold tabular-nums">{stats.checked_in}<span className="text-muted-foreground"> / {stats.total}</span></div>
                    <div className="mt-1 text-xs uppercase tracking-widest text-muted-foreground">checked in</div>
                    <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-muted">
                        <div className="h-full bg-foreground transition-all" style={{ width: `${pct}%` }} />
                    </div>
                </div>

                {/* Last scan result */}
                {scan && (
                    <div className={`mb-4 flex items-center gap-3 rounded-xl border p-4 ${scan.ok ? 'border-foreground bg-foreground text-background' : scan.already ? 'border-border bg-muted' : 'border-destructive/40 bg-destructive/10'}`}>
                        {scan.ok ? <CheckCircle2 className="size-6 shrink-0" /> : <XCircle className={`size-6 shrink-0 ${scan.already ? '' : 'text-destructive'}`} />}
                        <div>
                            {scan.name && <div className="font-semibold">{scan.name}</div>}
                            <div className={`text-sm ${scan.ok ? 'opacity-90' : scan.already ? 'text-muted-foreground' : 'text-destructive'}`}>{scan.message}</div>
                        </div>
                    </div>
                )}

                {/* Scan input */}
                <form onSubmit={(e) => { e.preventDefault(); check(token); }} className="mb-3 flex gap-2">
                    <input ref={inputRef} className={field} value={token} onChange={(e) => setToken(e.target.value)} placeholder="Scan or type ticket code…" autoComplete="off" />
                    <Button type="submit" size="lg" className="h-12 shrink-0" disabled={busy || !token.trim()}><ScanLine className="size-4" /> Check in</Button>
                </form>

                {cameraSupported && (
                    <Button type="button" variant="outline" className="mb-6 w-full" onClick={() => setCamera((c) => !c)}>
                        <Camera className="size-4" /> {camera ? 'Stop camera' : 'Scan with camera'}
                    </Button>
                )}
                {camera && (
                    <div className="mb-6 overflow-hidden rounded-xl border border-border">
                        <video ref={videoRef} className="aspect-video w-full bg-black object-cover" muted playsInline />
                    </div>
                )}

                {/* Recent */}
                <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Recent check-ins</h2>
                    {recent.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No one checked in yet.</p>
                    ) : (
                        <ul className="grid gap-1.5 text-sm">
                            {recent.map((r, i) => (
                                <li key={i} className="flex items-center justify-between border-b border-border/60 py-1.5 last:border-0">
                                    <span>{r.name}</span><span className="text-xs text-muted-foreground">{r.at}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}

CheckIn.layout = {
    breadcrumbs: [
        { title: 'Events', href: '/host/events' },
        { title: 'Check-in', href: '#' },
    ],
};
