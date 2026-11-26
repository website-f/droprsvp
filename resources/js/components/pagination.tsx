import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button, buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/** One entry of a Laravel paginator's `links` array. */
export interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

/**
 * The Laravel paginator as Inertia receives it (`->paginate()->withQueryString()`).
 * Everything is optional so pages whose paginator type only declares
 * `prev_page_url` / `next_page_url` can still pass it — they get Previous/Next only.
 */
export interface Paginator {
    current_page?: number;
    last_page?: number;
    prev_page_url?: string | null;
    next_page_url?: string | null;
    links?: PaginatorLink[];
    from?: number | null;
    to?: number | null;
    total?: number;
}

interface PaginationProps {
    paginator: Paginator;
    className?: string;
    /** Text of the "previous page" control. Defaults to "Previous". */
    prevLabel?: string;
    /** Text of the "next page" control. Defaults to "Next". */
    nextLabel?: string;
    /** What is being counted in "Showing X–Y of Z …", e.g. "invoices". */
    noun?: string;
    /** Keep the scroll position when changing page. Defaults to true. */
    preserveScroll?: boolean;
}

const ENTITIES: Record<string, string> = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", laquo: '«', raquo: '»', hellip: '…', nbsp: ' ' };

/** Decodes the few HTML entities Laravel puts in paginator labels (SSR-safe, no DOM). */
function decodeEntities(value: string): string {
    return value.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (match, entity: string) => {
        if (entity[0] === '#') {
            const code = entity[1] === 'x' || entity[1] === 'X' ? parseInt(entity.slice(2), 16) : parseInt(entity.slice(1), 10);

            return Number.isNaN(code) ? match : String.fromCodePoint(code);
        }

        return ENTITIES[entity.toLowerCase()] ?? match;
    });
}

const fmt = (n: number) => n.toLocaleString();

/**
 * Numbered pagination for Laravel paginators: Previous, page numbers (windowing
 * and "…" come from Laravel's own `links`, so they keep the query string), Next,
 * plus "Showing X–Y of Z". Phones get a compact Prev / "Page X of Y" / Next row.
 */
export function Pagination({ paginator, className, prevLabel = 'Previous', nextLabel = 'Next', noun, preserveScroll = true }: PaginationProps) {
    const { current_page: current, last_page: last, prev_page_url: prev = null, next_page_url: next = null, links, from, to, total } = paginator;

    if (last !== undefined ? last <= 1 : !prev && !next) {
        return null;
    }

    // Laravel's first and last entries are its own Previous/Next — we render ours.
    const pages = links && links.length > 2 ? links.slice(1, -1) : [];
    const pageOf = current !== undefined && last !== undefined ? `Page ${current} of ${last}` : null;
    const showing = from != null && to != null && total !== undefined ? `Showing ${fmt(from)}–${fmt(to)} of ${fmt(total)}${noun ? ` ${noun}` : ''}` : null;

    const edge = (url: string | null, label: string, dir: 'prev' | 'next') => {
        const content = dir === 'prev'
            ? <><ChevronLeft /><span>{label}</span></>
            : <><span>{label}</span><ChevronRight /></>;

        if (!url) {
            return (
                <span aria-disabled="true" className={cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'pointer-events-none opacity-50')}>
                    {content}
                </span>
            );
        }

        return (
            <Button asChild variant="outline" size="sm">
                <Link href={url} preserveScroll={preserveScroll} rel={dir}>{content}</Link>
            </Button>
        );
    };

    return (
        <nav aria-label="Pagination" className={cn('flex flex-col gap-2 text-sm sm:flex-row sm:items-center sm:justify-between', className)}>
            {showing ? <p className="hidden text-muted-foreground sm:block">{showing}</p> : <span className="hidden sm:block" />}

            {/* Phones: compact row that never overflows. */}
            <div className="flex items-center justify-between gap-2 sm:hidden">
                {edge(prev, prevLabel, 'prev')}
                <span className="min-w-0 truncate text-center text-xs text-muted-foreground">{pageOf ?? showing}</span>
                {edge(next, nextLabel, 'next')}
            </div>

            {/* sm and up: full numbered list. */}
            <div className="hidden flex-wrap items-center justify-end gap-1 sm:flex">
                {edge(prev, prevLabel, 'prev')}
                {pages.length > 0
                    ? pages.map((link, i) => {
                        const label = decodeEntities(link.label);

                        if (link.active) {
                            return (
                                <span key={`${i}-${label}`} aria-current="page" className={cn(buttonVariants({ variant: 'default', size: 'sm' }), 'min-w-8 px-2')}>
                                    {label}
                                </span>
                            );
                        }

                        if (!link.url) {
                            return <span key={`${i}-${label}`} className="min-w-6 px-1 text-center text-muted-foreground">{label}</span>;
                        }

                        return (
                            <Button key={`${i}-${label}`} asChild variant="outline" size="sm" className="min-w-8 px-2">
                                <Link href={link.url} preserveScroll={preserveScroll} aria-label={`Page ${label}`}>{label}</Link>
                            </Button>
                        );
                    })
                    : pageOf && <span className="px-2 text-muted-foreground">{pageOf}</span>}
                {edge(next, nextLabel, 'next')}
            </div>
        </nav>
    );
}
