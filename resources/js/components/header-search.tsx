import { router, usePage } from '@inertiajs/react';
import { MapPin, Search } from 'lucide-react';
import { useState } from 'react';

interface City { name: string; slug: string }

/**
 * Eventbrite-style header search: an event query + a location, submitting to the
 * discover page (`/en-my/{city}?q=…`). Responsive — stacks on small screens.
 */
export function HeaderSearch({ className = '' }: { className?: string }) {
    const cities = ((usePage().props as { cities?: City[] }).cities ?? []) as City[];
    const [q, setQ] = useState('');
    const [city, setCity] = useState('all');

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const base = city === 'all' ? '/en-my/all' : `/en-my/${city}`;
        router.get(base, q.trim() ? { q: q.trim() } : {});
    };

    return (
        <form onSubmit={submit} className={`flex w-full items-stretch gap-2 sm:gap-0 sm:rounded-full sm:border sm:border-border sm:bg-card sm:pl-2 sm:pr-1 sm:shadow-sm ${className}`}>
            {/* Query */}
            <label className="flex h-11 flex-1 items-center gap-2 rounded-full border border-border bg-card px-4 sm:h-12 sm:border-0 sm:bg-transparent sm:px-2">
                <Search className="size-4 shrink-0 text-muted-foreground" />
                <input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Search events"
                    aria-label="Search events"
                    className="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                />
            </label>

            {/* Location */}
            <div className="flex items-center gap-2 sm:border-l sm:border-border sm:pl-3">
                <label className="flex h-11 flex-1 items-center gap-2 rounded-full border border-border bg-card px-4 sm:h-12 sm:min-w-[9rem] sm:border-0 sm:bg-transparent sm:px-1">
                    <MapPin className="size-4 shrink-0 text-muted-foreground" />
                    <select
                        value={city}
                        onChange={(e) => setCity(e.target.value)}
                        aria-label="Location"
                        className="w-full cursor-pointer bg-transparent text-sm outline-none"
                    >
                        <option value="all">Anywhere</option>
                        {cities.map((c) => <option key={c.slug} value={c.slug}>{c.name}</option>)}
                    </select>
                </label>
                <button type="submit" aria-label="Search" className="flex size-11 shrink-0 items-center justify-center rounded-full bg-[#f05537] text-white transition-colors hover:bg-[#d8412a] sm:size-10">
                    <Search className="size-4" />
                </button>
            </div>
        </form>
    );
}
