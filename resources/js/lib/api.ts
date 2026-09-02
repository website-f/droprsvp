function cookie(name: string): string | undefined {
    return document.cookie
        .split('; ')
        .find((c) => c.startsWith(`${name}=`))
        ?.split('=')[1];
}

/** POST JSON to a same-origin endpoint with Laravel's XSRF header; resolves the parsed JSON. */
export async function postJson<T = unknown>(url: string, body?: unknown): Promise<T> {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(cookie('XSRF-TOKEN') ?? ''),
        },
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const data = (await res.json().catch(() => ({}))) as T;

    if (!res.ok) {
        throw Object.assign(new Error('Request failed'), { status: res.status, data });
    }

    return data;
}
