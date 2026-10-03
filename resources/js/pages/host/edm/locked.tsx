import { Head, Link } from '@inertiajs/react';
import { BarChart3, Crown, Lock, Mail, Users, Workflow } from 'lucide-react';
import { HOST_EDM_CRUMB } from '@/components/edm/ui';
import { Button } from '@/components/ui/button';

const PERKS = [
    { icon: Users, title: 'Your ticket holders', body: 'Email the people who joined your events and asked to hear from you.' },
    { icon: Mail, title: 'Drag-and-drop builder', body: 'Templates, your branding, and a preview on phone and desktop.' },
    { icon: Workflow, title: 'Automations', body: 'Reminders before your event, thank-yous after it, sent on their own.' },
    { icon: BarChart3, title: 'Opens and clicks', body: 'See who read it and what they clicked, for every campaign.' },
];

/** Email marketing is not available to this organizer: why, and — when Premium is the answer — how to get it. */
export default function EdmLocked({ reason, upgrade, back }: { reason: string; upgrade: boolean; back?: string }) {
    return (
        <>
            <Head title="Email marketing" />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col items-center p-4 py-10 text-center sm:py-16">
                <span className="flex size-14 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                    {upgrade ? <Crown className="size-7" /> : <Lock className="size-7" />}
                </span>
                <h1 className="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">{upgrade ? 'Email marketing comes with Premium' : 'Not available'}</h1>
                <p className="mt-2 max-w-lg text-muted-foreground">{reason}</p>

                {upgrade && (
                    <>
                        <div className="mt-8 grid w-full grid-cols-1 gap-3 text-left sm:grid-cols-2">
                            {PERKS.map((p) => (
                                <div key={p.title} className="flex gap-3 rounded-2xl border border-border bg-card p-4">
                                    <p.icon className="mt-0.5 size-5 shrink-0 text-primary" />
                                    <div><div className="font-semibold">{p.title}</div><div className="text-sm text-muted-foreground">{p.body}</div></div>
                                </div>
                            ))}
                        </div>
                        <Button asChild size="lg" className="mt-8"><Link href="/premium"><Crown className="size-4" /> Go Premium</Link></Button>
                    </>
                )}

                {back && <Button asChild variant="outline" className="mt-6"><Link href={back}>Back to Email marketing</Link></Button>}
            </div>
        </>
    );
}

EdmLocked.layout = { breadcrumbs: [HOST_EDM_CRUMB] };
