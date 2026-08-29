import { usePage } from '@inertiajs/react';

/** Sidebar brand: full wordmark when expanded, square mark when collapsed to icons. */
export default function AppLogo() {
    const b = usePage().props.branding;
    const full = b?.logo_full || '/logo-full.png';
    const mark = b?.logo_mark || '/logo-mark.png';
    const invert = (b?.invert_dark ?? true) ? 'dark:invert' : '';
    const height = b?.sidebar_height ?? 40;

    return (
        <>
            <img src={mark} alt="DropRSVP" className={`hidden size-9 shrink-0 rounded-md object-contain ${invert} group-data-[collapsible=icon]:block`} />
            <img src={full} alt="DropRSVP" style={{ height }} className={`ml-1 w-auto object-contain ${invert} group-data-[collapsible=icon]:hidden`} />
        </>
    );
}
