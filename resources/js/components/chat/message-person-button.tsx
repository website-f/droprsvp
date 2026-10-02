import { Link } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';

/**
 * The small "Message" button beside a person on a public page. `href` is the
 * signed link the server sends (null when there should be no button: guests,
 * yourself, guest checkouts, or someone you don't share the event with).
 */
export function MessagePersonButton({ href, name, label = false }: { href?: string | null; name: string; label?: boolean }) {
    if (!href) {
        return null;
    }

    return (
        <Link
            href={href}
            aria-label={`Message ${name}`}
            title={`Message ${name}`}
            className={`inline-flex shrink-0 items-center gap-1 rounded-full text-muted-foreground transition-colors hover:bg-primary/10 hover:text-primary ${label ? 'px-2 py-1 text-xs font-medium' : 'size-8 justify-center'}`}
        >
            <MessageCircle className="size-4" />
            {label && 'Message'}
        </Link>
    );
}
