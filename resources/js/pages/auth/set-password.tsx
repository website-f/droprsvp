import { Form, Head } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function SetPassword() {
    return (
        <>
            <Head title="Set your password" />
            <div className="mb-6 flex flex-col items-center text-center">
                <span className="mb-3 flex size-11 items-center justify-center rounded-2xl bg-foreground text-background"><KeyRound className="size-5" /></span>
                <p className="text-sm text-muted-foreground">Welcome! Choose a password to secure your account — you’ll use this to sign in from now on.</p>
            </div>

            <Form action="/set-password" method="post" resetOnSuccess={['password', 'password_confirmation']} disableWhileProcessing className="flex flex-col gap-6">
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="password">New password</Label>
                            <PasswordInput id="password" name="password" required autoFocus autoComplete="new-password" placeholder="New password" />
                            <InputError message={errors.password} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">Confirm password</Label>
                            <PasswordInput id="password_confirmation" name="password_confirmation" required autoComplete="new-password" placeholder="Confirm password" />
                            <InputError message={errors.password_confirmation} />
                        </div>
                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing && <Spinner />}
                            Set password &amp; continue
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

SetPassword.layout = {
    title: 'Set your password',
    description: 'Secure your new DropRSVP account',
};
