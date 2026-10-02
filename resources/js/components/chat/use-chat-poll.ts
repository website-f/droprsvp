import { useCallback, useEffect, useRef } from 'react';

export interface PollParams {
    mode: 'badge' | 'inbox' | 'open';
    c?: number;
    after?: number;
}

export interface PollResponse {
    v: number;
    bv: number;
    t: boolean;
    n: number;
    counts?: { unread: number; requests: number; announcements: number };
    inbox?: unknown[];
    open?: Record<string, unknown> & { id: number; messages: unknown[]; removed?: number[] };
    error?: string;
}

/**
 * The live-update loop. Built to feel real-time without costing the server:
 *
 *  - the SERVER sets the pace: every answer says how long to wait ("n"),
 *    fast while a conversation is live, slower when quiet, slower still under
 *    site-wide load;
 *  - a background tab waits at least `hiddenSeconds`; a tab with no mouse or
 *    keyboard for two minutes waits at least 15s; coming back polls at once;
 *  - one request at a time, a little jitter so tabs never synchronise, and
 *    exponential backoff on network errors (capped at a minute);
 *  - 429/403 answers are obeyed, not retried; offline pauses until online;
 *  - an expired token is refreshed from the session once, quietly.
 *
 * `pollNow()` asks immediately — after sending, on focus, on opening a thread.
 */
export function useChatPoll({ token: initialToken, v, bv, params, onData, hiddenSeconds = 60, fallbackSeconds = 10, initialDelay = 1, enabled = true }: {
    token: string;
    v: number;
    bv: number;
    /** Read on every tick, so it always reflects what is on screen. */
    params: () => PollParams;
    onData: (data: PollResponse) => void;
    hiddenSeconds?: number;
    fallbackSeconds?: number;
    /** Seconds before the first poll. The page props are fresh, so a badge can wait. */
    initialDelay?: number;
    enabled?: boolean;
}) {
    const token = useRef(initialToken);
    const version = useRef(v);
    const bversion = useRef(bv);
    const timer = useRef<number | null>(null);
    const inflight = useRef<AbortController | null>(null);
    const errors = useRef(0);
    const lastInput = useRef(0);
    const paramsRef = useRef(params);
    const onDataRef = useRef(onData);
    const tickRef = useRef<() => void>(() => {});

    useEffect(() => {
        paramsRef.current = params;
        onDataRef.current = onData;
    });

    const schedule = useCallback((seconds: number) => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }

        let delay = Math.max(1, seconds);

        if (document.visibilityState === 'hidden') {
            delay = Math.max(delay, hiddenSeconds);
        } else if (Date.now() - lastInput.current > 120_000) {
            delay = Math.max(delay, 15);
        }

        // ±10% so many tabs opened together drift apart.
        delay *= 0.9 + Math.random() * 0.2;
        timer.current = window.setTimeout(() => tickRef.current(), delay * 1000);
    }, [hiddenSeconds]);

    const refreshToken = useCallback(async (): Promise<boolean> => {
        try {
            const res = await fetch('/chat/token', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

            if (!res.ok) {
                return false;
            }

            token.current = (await res.json()).token;

            return true;
        } catch {
            return false;
        }
    }, []);

    const tick = useCallback(async () => {
        if (inflight.current || !navigator.onLine) {
            return;
        }

        const p = paramsRef.current();
        const qs = new URLSearchParams({
            mode: p.mode,
            v: String(version.current),
            bv: String(bversion.current),
            vis: document.visibilityState === 'visible' && document.hasFocus() ? '1' : '0',
        });

        if (p.c) {
            qs.set('c', String(p.c));
        }

        if (p.after) {
            qs.set('after', String(p.after));
        }

        const controller = new AbortController();
        inflight.current = controller;

        try {
            const res = await fetch(`/chat/poll?${qs}`, {
                headers: { Accept: 'application/json', Authorization: `Bearer ${token.current}` },
                cache: 'no-store',
                credentials: 'omit',
                signal: controller.signal,
            });

            if (res.status === 401) {
                inflight.current = null;
                schedule((await refreshToken()) ? 1 : 60);

                return;
            }

            const data = (await res.json().catch(() => ({}))) as PollResponse;

            if (res.status === 429 || res.status === 403) {
                // Told to back off: do exactly that.
                schedule(Number(res.headers.get('Retry-After')) || data.n || 60);
                inflight.current = null;

                return;
            }

            if (!res.ok) {
                throw new Error(String(res.status));
            }

            errors.current = 0;
            version.current = data.v;
            bversion.current = data.bv;
            onDataRef.current(data);
            schedule(data.n || fallbackSeconds);
        } catch (e) {
            if ((e as Error).name !== 'AbortError') {
                errors.current += 1;
                schedule(Math.min(60, fallbackSeconds * 2 ** Math.min(errors.current, 4)));
            }
        } finally {
            if (inflight.current === controller) {
                inflight.current = null;
            }
        }
    }, [schedule, refreshToken, fallbackSeconds]);

    useEffect(() => {
        tickRef.current = () => void tick();
    }, [tick]);

    const pollNow = useCallback(() => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }

        void tick();
    }, [tick]);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        lastInput.current = Date.now();
        schedule(initialDelay);

        const onVisible = () => (document.visibilityState === 'visible' ? pollNow() : schedule(hiddenSeconds));
        const onInput = () => {
            const wasIdle = Date.now() - lastInput.current > 120_000;
            lastInput.current = Date.now();

            if (wasIdle) {
                pollNow();
            }
        };

        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', pollNow);
        window.addEventListener('online', pollNow);
        window.addEventListener('pointerdown', onInput, { passive: true });
        window.addEventListener('keydown', onInput, { passive: true });

        return () => {
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('focus', pollNow);
            window.removeEventListener('online', pollNow);
            window.removeEventListener('pointerdown', onInput);
            window.removeEventListener('keydown', onInput);

            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }

            inflight.current?.abort();
        };
    }, [enabled, schedule, pollNow, hiddenSeconds, initialDelay]);

    return { pollNow };
}
