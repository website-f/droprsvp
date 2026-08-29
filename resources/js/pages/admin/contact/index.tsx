import { Head, Link, router } from '@inertiajs/react';
import { Check, Mail, Phone, RotateCcw } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Message {
    id: number; name: string; email: string; phone: string | null;
    category: string; message: string; handled: boolean; at: string;
}
interface Paginated { data: Message[]; prev_page_url: string | null; next_page_url: string | null }
interface Props { messages: Paginated; unhandled: number }

const CATEGORY_TINT: Record<string, string> = { support: '#3b82f6', sales: '#2ec4b6', enquiry: '#a855f7' };

export default function ContactInbox({ messages, unhandled }: Props) {
    const toggle = (id: number) => router.post(`/admin/contact/${id}/toggle`, {}, { preserveScroll: true });

    return (
        <>
            <Head title="Contact messages" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Contact messages</h1>
                        <p className="text-sm text-muted-foreground">Submissions from the public contact form.</p>
                    </div>
                    {unhandled > 0 && <Badge className="bg-foreground text-background">{unhandled} open</Badge>}
                </div>

                {messages.data.length === 0 ? (
                    <p className="rounded-2xl border border-dashed border-border p-12 text-center text-sm text-muted-foreground">No messages yet.</p>
                ) : (
                    <ul className="grid gap-3">
                        {messages.data.map((m) => (
                            <li key={m.id} className={`rounded-2xl border p-4 shadow-sm ${m.handled ? 'border-border bg-muted/30' : 'border-border bg-card'}`}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold">{m.name}</span>
                                            <Badge variant="secondary" className="capitalize" style={{ color: CATEGORY_TINT[m.category] }}>{m.category}</Badge>
                                            {m.handled && <Badge variant="outline" className="gap-1"><Check className="size-3" /> Handled</Badge>}
                                            <span className="text-xs text-muted-foreground">{m.at}</span>
                                        </div>
                                        <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                            <a href={`mailto:${m.email}`} className="flex items-center gap-1 hover:text-foreground"><Mail className="size-3.5" /> {m.email}</a>
                                            {m.phone && <a href={`tel:${m.phone}`} className="flex items-center gap-1 hover:text-foreground"><Phone className="size-3.5" /> {m.phone}</a>}
                                        </div>
                                    </div>
                                    <Button variant={m.handled ? 'ghost' : 'outline'} size="sm" onClick={() => toggle(m.id)}>
                                        {m.handled ? <><RotateCcw className="size-3.5" /> Reopen</> : <><Check className="size-3.5" /> Mark handled</>}
                                    </Button>
                                </div>
                                <p className="mt-3 whitespace-pre-line border-t border-border/60 pt-3 text-sm text-foreground/80">{m.message}</p>
                            </li>
                        ))}
                    </ul>
                )}

                {(messages.prev_page_url || messages.next_page_url) && (
                    <div className="mt-8 flex justify-between">
                        <Button asChild variant="outline" disabled={!messages.prev_page_url}>{messages.prev_page_url ? <Link href={messages.prev_page_url}>← Previous</Link> : <span>← Previous</span>}</Button>
                        <Button asChild variant="outline" disabled={!messages.next_page_url}>{messages.next_page_url ? <Link href={messages.next_page_url}>Next →</Link> : <span>Next →</span>}</Button>
                    </div>
                )}
            </div>
        </>
    );
}

ContactInbox.layout = {
    breadcrumbs: [{ title: 'Contact messages', href: '/admin/contact' }],
};
