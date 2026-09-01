import { router, usePage } from '@inertiajs/react';
import { MapPin, Search } from 'lucide-react';
import { useState } from 'react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface City { name: string; slug: string }

/**
 * Eventbrite-style header search: an event query + a location, submitting to the
 * discover page (`/en-my/{city}?q=…`). The location uses our themed Select (not a
 * native <select>) so it inherits dark mode + custom styling. Responsive — stacks
 * on small screens.
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
                <Select value={city} onValueChange={setCity}>
                    <SelectTrigger
                        aria-label="Location"
                        className="h-11 flex-1 gap-2 rounded-full border-border bg-card px-4 shadow-none sm:h-12 sm:min-w-[9rem] sm:border-0 sm:bg-transparent sm:px-1 sm:shadow-none dark:bg-card sm:dark:bg-transparent"
                    >
                        <MapPin className="size-4 shrink-0 text-muted-foreground" />
                        <SelectValue placeholder="Anywhere" />
                    </SelectTrigger>
                    <SelectContent className="max-h-72">
                        <SelectItem value="all">Anywhere</SelectItem>
                        {cities.map((c) => <SelectItem key={c.slug} value={c.slug}>{c.name}</SelectItem>)}
                    </SelectContent>
                </Select>
                <button type="submit" aria-label="Search" className="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-colors hover:bg-primary/90 sm:size-10">
                    <Search className="size-4" />
                </button>
            </div>
        </form>
    );
}
