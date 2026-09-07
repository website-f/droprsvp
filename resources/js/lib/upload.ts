import { toast } from 'sonner';

// Mirror the server rules (MediaController) so oversized/unsupported files are
// caught before the round-trip and the user gets an immediate, clear reason.
export const MAX_IMAGE_MB = 5;
const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

function bytes(n: number): string {
    if (n < 1024) {
        return `${n} B`;
    }

    if (n < 1024 * 1024) {
        return `${Math.round(n / 1024)} KB`;
    }

    return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

/** Returns a human error if the file isn't an allowed image within the size limit, else null. */
export function imageError(file: File): string | null {
    if (file.type && !ALLOWED_IMAGE_TYPES.includes(file.type)) {
        return `“${file.name}” isn’t a supported image. Use JPG, PNG, WEBP or GIF.`;
    }

    if (file.size > MAX_IMAGE_MB * 1024 * 1024) {
        return `“${file.name}” is ${bytes(file.size)} — the limit is ${MAX_IMAGE_MB} MB.`;
    }

    return null;
}

/**
 * Validate + upload an image, surfacing any problem (too large, wrong type, server
 * rejection) as a toast. Resolves to the public URL, or null on failure — so callers
 * can simply `const url = await uploadImageWithToast(f); if (url) setData(...)`.
 */
export async function uploadImageWithToast(file: File): Promise<string | null> {
    const err = imageError(file);

    if (err) {
        toast.error(err);

        return null;
    }

    try {
        return await uploadImage(file);
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'Upload failed. Please try again.');

        return null;
    }
}

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
