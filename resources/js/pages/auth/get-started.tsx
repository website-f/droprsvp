import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, CheckCircle2, Mail, QrCode, Ticket } from 'lucide-react';
import { useState } from 'react';
import { Wordmark } from '@/components/brand';
import { Button } from '@/components/ui/button';
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';

const field = 'h-12 w-full rounded-xl border border-input bg-background px-4 text-base outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function GetStarted() {
    const [step, setStep] = useState(1);
    const form = useForm({ email: '', code: '', first_name: '', last_name: '', password: '', password_confirmation: '' });
    const { data, setData, processing, errors } = form;

    const sendCode = (e: React.FormEvent) => {
 e.preventDefault(); form.post('/get-started/code', { preserveScroll: true, onSuccess: () => setStep(2) }); 
};
    const verify = (e: React.FormEvent) => {
 e.preventDefault(); form.post('/get-started/verify', { preserveScroll: true, onSuccess: () => setStep(3) }); 
};
    const complete = (e: React.FormEvent) => {
 e.preventDefault(); form.post('/get-started/complete'); 
};
    const resend = () => form.post('/get-started/code', { preserveScroll: true });

    return (
        <>
            <Head title="Create your organizer account — DropRSVP" />
            <div className="grid min-h-screen lg:grid-cols-2">
                {/* Brand panel */}
                <div className="relative hidden overflow-hidden bg-foreground p-12 text-background lg:flex lg:flex-col">
                    <div aria-hidden className="pointer-events-none absolute -right-24 -top-24 size-96 rounded-full opacity-40 blur-3xl" style={{ background: 'radial-gradient(circle,#6c63ff,transparent 70%)' }} />
                    <div aria-hidden className="pointer-events-none absolute -bottom-28 -left-16 size-96 rounded-full opacity-30 blur-3xl" style={{ background: 'radial-gradient(circle,#ff6584,transparent 70%)' }} />
                    <Link href="/" className="relative" aria-label="DropRSVP home"><Wordmark className="h-9" onDark /></Link>
                    <div className="relative mt-auto max-w-md">
                        <h1 className="text-4xl font-bold leading-tight tracking-tight">Start selling tickets in minutes.</h1>
                        <p className="mt-4 text-background/70">Create events, manage seating, take payments and check guests in — all in one place.</p>
                        <ul className="mt-8 space-y-3 text-sm">
                            {[[Ticket, 'Multi-tier ticketing & discount codes'], [CalendarDays, 'Seating, sessions & attendee management'], [QrCode, 'Fast QR check-in at the door']].map(([Icon, t], i) => (
                                <li key={i} className="flex items-center gap-3"><span className="flex size-8 items-center justify-center rounded-full bg-background/10"><Icon className="size-4" /></span>{t as string}</li>
                            ))}
                        </ul>
                    </div>
                </div>

                {/* Form panel */}
                <div className="flex flex-col px-6 py-8 sm:px-10">
                    <div className="flex items-center justify-between">
                        <Link href="/" className="lg:hidden" aria-label="DropRSVP home"><Wordmark className="h-8" /></Link>
                        <span className="ml-auto text-sm text-muted-foreground">Have an account? <Link href="/login" className="font-medium text-foreground underline underline-offset-4">Log in</Link></span>
                    </div>

                    <div className="mx-auto my-auto w-full max-w-md py-10">
                        {/* progress */}
                        <div className="mb-8 flex items-center gap-2">
                            {[1, 2, 3].map((n) => (
                                <div key={n} className={`h-1.5 flex-1 rounded-full ${n <= step ? 'bg-foreground' : 'bg-border'}`} />
                            ))}
                        </div>

                        {step === 1 && (
                            <form onSubmit={sendCode} className="grid gap-5">
                                <div>
                                    <h2 className="text-2xl font-bold tracking-tight">Create your account</h2>
                                    <p className="mt-1.5 text-sm text-muted-foreground">Enter your email and we’ll send you a verification code.</p>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="email">Email address</Label>
                                    <input id="email" type="email" autoFocus autoComplete="email" className={field} value={data.email} onChange={(e) => setData('email', e.target.value)} placeholder="you@example.com" />
                                    {errors.email && <p className="text-xs text-destructive">{errors.email}</p>}
                                </div>
                                <Button type="submit" size="lg" className="h-12" disabled={processing}><Mail className="size-4" /> Send code</Button>
                                <p className="text-center text-xs text-muted-foreground">By continuing you agree to the Terms & Privacy Policy.</p>
                            </form>
                        )}

                        {step === 2 && (
                            <form onSubmit={verify} className="grid gap-5">
                                <button type="button" onClick={() => setStep(1)} className="flex w-max items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> Back</button>
                                <div>
                                    <h2 className="text-2xl font-bold tracking-tight">Enter the code</h2>
                                    <p className="mt-1.5 text-sm text-muted-foreground">We sent a 6-digit code to <span className="font-medium text-foreground">{data.email}</span>.</p>
                                </div>
                                <div className="grid gap-1.5">
                                    <InputOTP maxLength={6} value={data.code} onChange={(v) => setData('code', v)}>
                                        <InputOTPGroup>
                                            {[0, 1, 2, 3, 4, 5].map((i) => <InputOTPSlot key={i} index={i} className="size-12 text-lg" />)}
                                        </InputOTPGroup>
                                    </InputOTP>
                                    {errors.code && <p className="text-xs text-destructive">{errors.code}</p>}
                                </div>
                                <Button type="submit" size="lg" className="h-12" disabled={processing || data.code.length < 6}>Verify & continue</Button>
                                <p className="text-center text-sm text-muted-foreground">Didn’t get it? <button type="button" onClick={resend} className="font-medium text-foreground underline underline-offset-4">Resend code</button></p>
                            </form>
                        )}

                        {step === 3 && (
                            <form onSubmit={complete} className="grid gap-5">
                                <div className="flex items-center gap-2 rounded-lg bg-secondary px-3 py-2 text-sm"><CheckCircle2 className="size-4 text-foreground" /> Email verified</div>
                                <div>
                                    <h2 className="text-2xl font-bold tracking-tight">Tell us your name</h2>
                                    <p className="mt-1.5 text-sm text-muted-foreground">And set a password to secure your account.</p>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="first">First name</Label>
                                        <input id="first" autoFocus className={field} value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} />
                                        {errors.first_name && <p className="text-xs text-destructive">{errors.first_name}</p>}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="last">Last name</Label>
                                        <input id="last" className={field} value={data.last_name} onChange={(e) => setData('last_name', e.target.value)} />
                                        {errors.last_name && <p className="text-xs text-destructive">{errors.last_name}</p>}
                                    </div>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="password">Password</Label>
                                    <input id="password" type="password" autoComplete="new-password" className={field} value={data.password} onChange={(e) => setData('password', e.target.value)} />
                                    {errors.password && <p className="text-xs text-destructive">{errors.password}</p>}
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="password_confirmation">Confirm password</Label>
                                    <input id="password_confirmation" type="password" autoComplete="new-password" className={field} value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} />
                                </div>
                                <Button type="submit" size="lg" className="h-12" disabled={processing}>Create account</Button>
                            </form>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
