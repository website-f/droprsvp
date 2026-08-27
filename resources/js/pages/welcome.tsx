import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { dashboard, register } from '@/routes';
import { Button } from '@/components/ui/button';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { QrCode, Search, Ticket, Users } from 'lucide-react';

const catSlug = (name: string) => name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');

/**
 * DropRSVP marketing landing — a placeholder P0 hero that exercises the DESIGN.md
 * design tokens (Meetup "warm minimalism": generous whitespace, pill buttons,
 * signature primary reserved for CTAs). Colours come from the theme tokens in
 * app.css, so the whole page re-skins the moment the Tiratech palette lands.
 */
export default function Welcome() {
    const { auth } = usePage().props;
    const [q, setQ] = useState('');

    const categories = ['Music', 'Business', 'Food & Drink', 'Tech', 'Community', 'Sports', 'Arts', 'Wellness'];

    return (
        <>
            <Head title="Find your people — DropRSVP" />

            <div className="min-h-screen bg-background text-foreground">
                <PublicHeader />

                {/* Hero */}
                <section className="mx-auto max-w-[1280px] px-6 pt-10 pb-16 text-center lg:pt-20 lg:pb-24">
                    <h1 className="mx-auto max-w-4xl text-4xl font-bold leading-tight tracking-tight text-balance sm:text-5xl lg:text-6xl">
                        Find your people.<br className="hidden sm:block" /> Fill your events.
                    </h1>
                    <p className="mx-auto mt-6 max-w-2xl text-lg text-muted-foreground">
                        Discover events happening near you, or host your own — sell tickets, manage seating and
                        check guests in, all from one place.
                    </p>

                    {/* Search → the discovery page */}
                    <form onSubmit={(e) => { e.preventDefault(); router.get('/events', q ? { q } : {}); }} className="mx-auto mt-10 flex max-w-2xl flex-col gap-3 sm:flex-row">
                        <label className="flex h-12 flex-1 items-center gap-2 rounded-full border border-border bg-card px-4 shadow-sm">
                            <Search className="size-4 shrink-0 text-muted-foreground" />
                            <input
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                className="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                                placeholder="Search events, e.g. 'live music'"
                            />
                        </label>
                        <Button type="submit" size="lg" className="h-12">Search</Button>
                    </form>

                    {/* Category chips → filtered discovery */}
                    <div className="mx-auto mt-8 flex max-w-3xl flex-wrap justify-center gap-2">
                        {categories.map((c) => (
                            <Link
                                key={c}
                                href={`/events?category=${catSlug(c)}`}
                                className="rounded-full border border-border bg-card px-4 py-1.5 text-sm text-foreground/80 transition-colors hover:border-primary hover:text-primary"
                            >
                                {c}
                            </Link>
                        ))}
                    </div>

                    <div className="mt-12 flex justify-center gap-3">
                        <Button asChild size="lg" className="h-12 px-8">
                            <Link href={auth?.user ? dashboard() : register()}>Create an event</Link>
                        </Button>
                        <Button asChild size="lg" variant="outline" className="h-12 px-8">
                            <Link href="/events">Browse events</Link>
                        </Button>
                    </div>
                </section>

                {/* Feature strip */}
                <section className="border-t border-border bg-muted/40">
                    <div className="mx-auto grid max-w-[1280px] gap-6 px-6 py-16 sm:grid-cols-3">
                        {[
                            { icon: Users, title: 'Discover & connect', body: 'A community-first marketplace — browse by interest and location and find events worth showing up for.' },
                            { icon: Ticket, title: 'Sell tickets', body: 'Multi-tier ticketing, discount codes, seat & table management and secure online payments.' },
                            { icon: QrCode, title: 'Check them in', body: 'QR entry passes and a fast scanner so your team gets everyone through the door.' },
                        ].map(({ icon: Icon, title, body }) => (
                            <div key={title} className="rounded-2xl border border-border bg-card p-6 shadow-sm">
                                <div className="flex size-11 items-center justify-center rounded-full bg-primary/10 text-primary">
                                    <Icon className="size-5" />
                                </div>
                                <h3 className="mt-4 text-lg font-semibold">{title}</h3>
                                <p className="mt-2 text-sm text-muted-foreground">{body}</p>
                            </div>
                        ))}
                    </div>
                </section>

                <PublicFooter />
            </div>
        </>
    );
}
