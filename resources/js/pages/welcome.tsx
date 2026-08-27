import { Head, Link, usePage } from '@inertiajs/react';
import { dashboard, login, register } from '@/routes';
import { Button } from '@/components/ui/button';
import { CalendarDays, MapPin, QrCode, Search, Ticket, Users } from 'lucide-react';

/**
 * DropRSVP marketing landing — a placeholder P0 hero that exercises the DESIGN.md
 * design tokens (Meetup "warm minimalism": generous whitespace, pill buttons,
 * signature primary reserved for CTAs). Colours come from the theme tokens in
 * app.css, so the whole page re-skins the moment the Tiratech palette lands.
 */
export default function Welcome() {
    const { auth } = usePage().props;

    const categories = ['Music', 'Business', 'Food & Drink', 'Tech', 'Community', 'Sports', 'Arts', 'Wellness'];

    return (
        <>
            <Head title="Find your people — DropRSVP" />

            <div className="min-h-screen bg-background text-foreground">
                {/* Nav */}
                <header className="mx-auto flex max-w-[1280px] items-center justify-between px-6 py-5">
                    <span className="text-2xl font-bold tracking-tight">
                        Drop<span className="text-primary">RSVP</span>
                    </span>
                    <nav className="flex items-center gap-2">
                        {auth?.user ? (
                            <Button asChild><Link href={dashboard()}>Dashboard</Link></Button>
                        ) : (
                            <>
                                <Button asChild variant="ghost"><Link href={login()}>Log in</Link></Button>
                                <Button asChild><Link href={register()}>Sign up</Link></Button>
                            </>
                        )}
                    </nav>
                </header>

                {/* Hero */}
                <section className="mx-auto max-w-[1280px] px-6 pt-10 pb-16 text-center lg:pt-20 lg:pb-24">
                    <h1 className="mx-auto max-w-4xl text-4xl font-bold leading-tight tracking-tight text-balance sm:text-5xl lg:text-6xl">
                        Find your people.<br className="hidden sm:block" /> Fill your events.
                    </h1>
                    <p className="mx-auto mt-6 max-w-2xl text-lg text-muted-foreground">
                        Discover events happening near you, or host your own — sell tickets, manage seating and
                        check guests in, all from one place.
                    </p>

                    {/* Search (visual placeholder for the discovery bar) */}
                    <div className="mx-auto mt-10 flex max-w-2xl flex-col gap-3 sm:flex-row">
                        <label className="flex h-12 flex-1 items-center gap-2 rounded-full border border-border bg-card px-4 shadow-sm">
                            <Search className="size-4 shrink-0 text-muted-foreground" />
                            <input
                                className="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                                placeholder="Search events, e.g. 'live music'"
                            />
                        </label>
                        <label className="flex h-12 items-center gap-2 rounded-full border border-border bg-card px-4 shadow-sm sm:w-56">
                            <MapPin className="size-4 shrink-0 text-muted-foreground" />
                            <input
                                className="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                                placeholder="Kuala Lumpur"
                            />
                        </label>
                        <Button size="lg" className="h-12">Search</Button>
                    </div>

                    {/* Category chips */}
                    <div className="mx-auto mt-8 flex max-w-3xl flex-wrap justify-center gap-2">
                        {categories.map((c) => (
                            <span
                                key={c}
                                className="rounded-full border border-border bg-card px-4 py-1.5 text-sm text-foreground/80 transition-colors hover:border-primary hover:text-primary"
                            >
                                {c}
                            </span>
                        ))}
                    </div>

                    <div className="mt-12">
                        <Button asChild size="lg" className="h-12 px-8">
                            <Link href={auth?.user ? dashboard() : register()}>Create an event</Link>
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

                {/* Footer */}
                <footer className="mx-auto flex max-w-[1280px] flex-col items-center justify-between gap-3 px-6 py-10 text-sm text-muted-foreground sm:flex-row">
                    <span className="flex items-center gap-2">
                        <CalendarDays className="size-4" /> DropRSVP
                    </span>
                    <span>&copy; {new Date().getFullYear()} DropRSVP. All rights reserved.</span>
                </footer>
            </div>
        </>
    );
}
