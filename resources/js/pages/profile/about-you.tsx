import { Head, useForm } from '@inertiajs/react';
import { UserRound } from 'lucide-react';
import { Wordmark } from '@/components/brand';
import { StateCityPicker  } from '@/components/state-city-picker';
import type {CityOption} from '@/components/state-city-picker';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

interface Profile { phone: string | null; gender: string; birth_year: number | null; city: string | null; country: string | null }
interface Props { profile: Profile; countries: string[]; cities: CityOption[]; done: boolean }

const field = 'h-11 w-full rounded-xl border border-input bg-card px-3.5 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const GENDERS = [{ value: 'na', label: 'Prefer not to say' }, { value: 'female', label: 'Female' }, { value: 'male', label: 'Male' }, { value: 'other', label: 'Other' }];
/** Newest first. A year is a fact; a band goes stale on the next birthday. */
const BIRTH_YEARS = Array.from({ length: new Date().getFullYear() - 1920 + 1 }, (_, i) => {
    const y = String(new Date().getFullYear() - i);

    return { value: y, label: y };
});

export default function AboutYou({ profile, countries, cities, done }: Props) {
    const form = useForm({
        phone: profile.phone ?? '',
        gender: profile.gender ?? 'na',
        birth_year: profile.birth_year != null ? String(profile.birth_year) : '',
        city: profile.city ?? '',
        country: profile.country ?? 'Malaysia',
    });
    const { data, setData, processing, errors } = form;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/profile/about-you');
    };

    return (
        <>
            <Head title="About you" />
            <div className="flex min-h-screen flex-col bg-muted/30">
                <header className="flex items-center justify-between px-6 py-5">
                    <Wordmark className="h-8" />
                </header>

                <div className="mx-auto flex w-full max-w-lg flex-1 flex-col justify-center px-6 pb-16">
                    <span className="flex size-12 items-center justify-center rounded-2xl bg-foreground text-background"><UserRound className="size-6" /></span>
                    <h1 className="mt-5 text-2xl font-bold tracking-tight sm:text-3xl">{done ? 'Update your details' : 'A few quick details'}</h1>
                    <p className="mt-1.5 text-sm text-muted-foreground">This helps organizers understand who’s coming — it’s only visible to them and us.</p>

                    <form onSubmit={submit} className="mt-8 grid gap-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="phone">Phone</Label>
                            <input id="phone" className={field} value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="+60 12-345 6789" />
                            {errors.phone && <p className="text-xs text-destructive">{errors.phone}</p>}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label>Gender</Label>
                                <AppSelect value={data.gender} onChange={(v) => setData('gender', v)} options={GENDERS} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Year you were born</Label>
                                <AppSelect value={data.birth_year || ''} onChange={(v) => setData('birth_year', v)} options={[{ value: '', label: '—' }, ...BIRTH_YEARS]} />
                                {errors.birth_year && <p className="text-xs text-destructive">{errors.birth_year}</p>}
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Country</Label>
                                <AppSelect value={data.country} onChange={(v) => setData('country', v)} options={countries.map((c) => ({ value: c, label: c }))} />
                                {errors.country && <p className="text-xs text-destructive">{errors.country}</p>}
                            </div>
                            {/* Country first, because it decides whether the next
                                two fields are Malaysian pickers or a free-text box. */}
                            <StateCityPicker
                                cities={cities}
                                value={data.city}
                                onChange={(v) => setData('city', v)}
                                country={data.country}
                                error={errors.city}
                            />
                        </div>
                        <Button type="submit" size="lg" className="mt-2" disabled={processing}>{done ? 'Save' : 'Continue'}</Button>
                    </form>
                </div>
            </div>
        </>
    );
}
