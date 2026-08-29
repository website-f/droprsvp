import { Head, Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { Wordmark } from '@/components/brand';
import { Button } from '@/components/ui/button';

export default function OrganizerPending({ submitted_at }: { submitted_at: string | null }) {
    return (
        <>
            <Head title="Application under review" />
            <div className="flex min-h-screen flex-col bg-muted/30">
                <header className="flex items-center justify-between px-6 py-5">
                    <Wordmark className="h-8" />
                </header>

                <div className="mx-auto flex w-full max-w-md flex-1 flex-col items-center justify-center px-6 pb-16 text-center">
                    <span className="flex size-14 items-center justify-center rounded-2xl bg-foreground text-background"><Clock className="size-7" /></span>
                    <h1 className="mt-5 text-2xl font-bold tracking-tight sm:text-3xl">Application under review</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Thanks — we’ve received your vendor application{submitted_at ? ` on ${submitted_at}` : ''}. Our team reviews every applicant and will be in touch by <span className="font-medium text-foreground">email or phone</span> once you’re approved.
                    </p>
                    <div className="mt-8 flex gap-3">
                        <Button asChild variant="outline"><Link href="/host/apply">Edit application</Link></Button>
                        <Button asChild><Link href="/en-my">Browse events</Link></Button>
                    </div>
                </div>
            </div>
        </>
    );
}
