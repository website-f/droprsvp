import { Head, Link } from '@inertiajs/react';
import { Clock, Lock, Pencil } from 'lucide-react';
import { useState } from 'react';
import { Wordmark } from '@/components/brand';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

export default function OrganizerPending({ submitted_at, editable = false }: { submitted_at: string | null; editable?: boolean }) {
    const [lockedOpen, setLockedOpen] = useState(false);

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

                    {editable ? (
                        <p className="mt-4 flex items-center gap-1.5 rounded-full bg-secondary px-3 py-1 text-xs font-medium text-muted-foreground">
                            <Pencil className="size-3.5" /> You can still edit your application until our team opens it for review.
                        </p>
                    ) : (
                        <p className="mt-4 flex items-center gap-1.5 rounded-full bg-secondary px-3 py-1 text-xs font-medium text-muted-foreground">
                            <Lock className="size-3.5" /> Your application is now being reviewed and can no longer be edited.
                        </p>
                    )}

                    <div className="mt-8 flex gap-3">
                        {editable ? (
                            <Button asChild variant="outline"><Link href="/host/apply"><Pencil className="size-4" /> Edit application</Link></Button>
                        ) : (
                            <Button variant="outline" onClick={() => setLockedOpen(true)}><Lock className="size-4" /> Edit application</Button>
                        )}
                        <Button asChild><Link href="/en-my">Browse events</Link></Button>
                    </div>
                </div>
            </div>

            <Dialog open={lockedOpen} onOpenChange={setLockedOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2"><Lock className="size-5" /> Editing locked</DialogTitle>
                        <DialogDescription>
                            Our team is reviewing your application, so it can no longer be edited. If your details have changed or you need to make a correction, please reach out to us and we’ll help.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button asChild variant="outline"><Link href="/en-my/contact/">Contact support</Link></Button>
                        <Button onClick={() => setLockedOpen(false)}>Got it</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
