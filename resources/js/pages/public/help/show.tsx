import { Head, Link } from '@inertiajs/react';
import { contentClass } from '@/components/rich-editor';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { ArrowLeft, ChevronRight } from 'lucide-react';

interface Article { title: string; category: string; body: string | null }
interface Related { title: string; slug: string }

export default function HelpShow({ article, related }: { article: Article; related: Related[] }) {
    return (
        <>
            <Head title={`${article.title} · Help`} />
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-10">
                    <nav className="mb-6 flex items-center gap-1.5 text-sm text-muted-foreground">
                        <Link href="/help" className="hover:text-foreground">Help center</Link>
                        <ChevronRight className="size-3.5" />
                        <span className="text-foreground">{article.category}</span>
                    </nav>

                    <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">{article.title}</h1>
                    <article className={`mt-6 ${contentClass}`} dangerouslySetInnerHTML={{ __html: article.body ?? '' }} />

                    {related.length > 0 && (
                        <div className="mt-12 rounded-2xl border border-border bg-card p-6">
                            <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Related articles</h2>
                            <ul className="mt-3 grid gap-1">
                                {related.map((r) => (
                                    <li key={r.slug}>
                                        <Link href={`/help/${r.slug}`} className="flex items-center justify-between gap-3 rounded-lg px-2 py-2 text-sm font-medium transition-colors hover:bg-accent">
                                            {r.title}<ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <Link href="/help" className="mt-8 inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> Back to help center</Link>
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
