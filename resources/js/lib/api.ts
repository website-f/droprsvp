function cookie(name: string): string | undefined {
    return document.cookie
        .split('; ')
        .find((c) => c.startsWith(`${name}=`))
        ?.split('=')[1];
}

/**
 * POST JSON to a same-origin endpoint with Laravel's XSRF header; resolves the parsed JSON.
 *
 * `timeoutMs` aborts a request that hangs (a door scanner on patchy venue wifi
 * must not sit on "checking…" forever); the rejection has `timeout: true`.
 */
export async function postJson<T = unknown>(url: string, body?: unknown, { timeoutMs }: { timeoutMs?: number } = {}): Promise<T> {
    const controller = timeoutMs ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), timeoutMs) : null;
    let res: Response;

    try {
        res = await fetch(url, {
            signal: controller?.signal,
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(cookie('XSRF-TOKEN') ?? ''),
            },
            credentials: 'same-origin',
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    } catch (e) {
        throw Object.assign(new Error('Request failed'), { status: 0, timeout: controller?.signal.aborted ?? false, cause: e });
    } finally {
        if (timer) {
            clearTimeout(timer);
        }
    }

    const data = (await res.json().catch(() => ({}))) as T;

    if (!res.ok) {
        throw Object.assign(new Error('Request failed'), { status: res.status, data });
    }

    return data;
}
