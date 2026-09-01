import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Store, Ticket } from 'lucide-react';
import { Wordmark } from '@/components/brand';

/**
 * The "Sign up" landing — lets a visitor pick their path (attend events vs host &
 * sell tickets) before we send them to the right flow. Own chrome (null layout).
 */
export default function Choose() {
    return (
        <>
            <Head title="Sign up" />
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="border-b border-border">
                    <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                        <Link href="/" aria-label="DropRSVP home"><Wordmark height={32} /></Link>
                        <Link href="/login" className="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground">Log in</Link>
                    </div>
                </header>

                <main className="mx-auto flex w-full max-w-4xl flex-1 flex-col justify-center px-6 py-12">
                    <div className="text-center">
                        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">How do you want to use DropRSVP?</h1>
                        <p className="mt-3 text-sm text-muted-foreground sm:text-base">Pick the option that fits you — you can always do the other later.</p>
                    </div>

                    <div className="mt-10 grid gap-5 sm:grid-cols-2">
                        {/* Attendee */}
                        <Link
                            href="/register"
                            className="group flex flex-col rounded-2xl border border-border bg-card p-7 shadow-sm transition-all hover:-translate-y-0.5 hover:border-foreground/30 hover:shadow-md"
                        >
                            <span className="flex size-12 items-center justify-center rounded-xl bg-foreground text-background"><Ticket className="size-6" /></span>
                            <h2 className="mt-5 text-lg font-bold">I want to attend events</h2>
                            <p className="mt-2 flex-1 text-sm text-muted-foreground">Discover events near you, buy tickets, and keep them all in one place with QR check-in.</p>
                            <span className="mt-6 inline-flex items-center gap-1.5 text-sm font-semibold text-foreground">
                                Create a free account <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                            </span>
                        </Link>

                        {/* Vendor */}
                        <Link
                            href="/get-started"
                            className="group flex flex-col rounded-2xl border border-border bg-card p-7 shadow-sm transition-all hover:-translate-y-0.5 hover:border-foreground/30 hover:shadow-md"
                        >
                            <span className="flex size-12 items-center justify-center rounded-xl bg-[#6c63ff] text-white"><Store className="size-6" /></span>
                            <h2 className="mt-5 text-lg font-bold">I want to host &amp; sell tickets</h2>
                            <p className="mt-2 flex-1 text-sm text-muted-foreground">Create events, sell multi-tier tickets, manage seating, take payments and check guests in.</p>
                            <span className="mt-6 inline-flex items-center gap-1.5 text-sm font-semibold text-foreground">
                                Become a vendor <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                            </span>
                        </Link>
                    </div>

                    <p className="mt-8 text-center text-sm text-muted-foreground">
                        Already have an account?{' '}
                        <Link href="/login" className="font-medium text-foreground underline underline-offset-4">Log in</Link>
                    </p>
                </main>
            </div>
        </>
    );
}
