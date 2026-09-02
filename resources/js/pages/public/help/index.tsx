import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { ChevronRight, LifeBuoy, Search } from 'lucide-react';

interface Article { title: string; slug: string; excerpt: string | null }
interface Category { name: string; articles: Article[] }

export default function HelpIndex({ categories, filters }: { categories: Category[]; filters: { q: string } }) {
    const [q, setQ] = useState(filters.q);

    return (
        <>
            <Head title="Help center" />
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                {/* Hero + search */}
                <section className="border-b border-border bg-muted/30">
                    <div className="mx-auto w-full max-w-3xl px-6 py-14 text-center sm:py-20">
                        <span className="inline-flex size-12 items-center justify-center rounded-2xl bg-foreground text-background"><LifeBuoy className="size-6" /></span>
                        <h1 className="mt-5 text-3xl font-bold tracking-tight sm:text-4xl">How can we help?</h1>
                        <p className="mt-2 text-sm text-muted-foreground sm:text-base">Search our guides, or browse by topic below.</p>
                        <form onSubmit={(e) => { e.preventDefault(); router.get('/help', q ? { q } : {}, { preserveState: true }); }} className="mx-auto mt-7 flex h-14 max-w-xl items-center gap-3 rounded-full border border-border bg-card px-5 shadow-sm focus-within:border-foreground/40">
                            <Search className="size-5 shrink-0 text-muted-foreground" />
                            <input value={q} onChange={(e) => setQ(e.target.value)} className="h-full w-full bg-transparent text-base outline-none placeholder:text-muted-foreground" placeholder="Search for answers…" aria-label="Search help" />
                        </form>
                    </div>
                </section>

                <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-12">
                    {categories.length === 0 ? (
                        <p className="text-center text-sm text-muted-foreground">No articles found{filters.q ? ` for “${filters.q}”` : ''}.</p>
                    ) : (
                        <div className="grid gap-6 md:grid-cols-2">
                            {categories.map((cat) => (
                                <section key={cat.name} className="rounded-2xl border border-border bg-card p-6 shadow-sm">
                                    <h2 className="text-lg font-semibold">{cat.name}</h2>
                                    <ul className="mt-4 grid gap-1">
                                        {cat.articles.map((a) => (
                                            <li key={a.slug}>
                                                <Link href={`/help/${a.slug}`} className="group flex items-center justify-between gap-3 rounded-lg px-2 py-2 text-sm transition-colors hover:bg-accent">
                                                    <span className="font-medium">{a.title}</span>
                                                    <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ))}
                        </div>
                    )}

                    <div className="mt-12 rounded-2xl border border-border bg-muted/30 p-8 text-center">
                        <h3 className="text-lg font-semibold">Still need help?</h3>
                        <p className="mx-auto mt-1.5 max-w-md text-sm text-muted-foreground">Can’t find what you’re looking for? Reach out and we’ll get back to you.</p>
                        <Button asChild className="mt-5"><Link href="/">Contact us</Link></Button>
                    </div>
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
