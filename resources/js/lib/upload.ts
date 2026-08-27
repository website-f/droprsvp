function cookie(name: string): string | undefined {
    return document.cookie
        .split('; ')
        .find((c) => c.startsWith(`${name}=`))
        ?.split('=')[1];
}

/** Upload an image to the CMS media endpoint and resolve its public URL. */
export async function uploadImage(file: File): Promise<string> {
    const token = decodeURIComponent(cookie('XSRF-TOKEN') ?? '');
    const body = new FormData();
    body.append('file', file);

    const res = await fetch('/admin/cms/media', {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
        body,
        credentials: 'same-origin',
    });

    if (!res.ok) {
        throw new Error('Upload failed');
    }
    const data = (await res.json()) as { url: string };

    return data.url;
}
