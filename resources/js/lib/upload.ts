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

    const res = await fetch('/uploads', {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
        body,
        credentials: 'same-origin',
    });

    if (!res.ok) {
        // Surface the server's reason (validation message, or "file too large" when the
        // image exceeds the host's PHP upload_max_filesize and Laravel sees an empty upload)
        // instead of a generic failure, so problems are diagnosable in production.
        let message = 'Upload failed';

        try {
            const err = (await res.json()) as { message?: string; errors?: Record<string, string[]> };
            message = err.errors?.file?.[0] ?? err.message ?? message;
        } catch {
            if (res.status === 413) {
                message = 'Image is too large. Please upload a file under 5 MB.';
            }
        }

        throw new Error(message);
    }

    const data = (await res.json()) as { url: string };

    return data.url;
}
