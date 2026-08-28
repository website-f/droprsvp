/** Sidebar brand: full wordmark when expanded, square mark when collapsed to icons. */
export default function AppLogo() {
    return (
        <>
            <img src="/logo-mark.png" alt="DropRSVP" className="hidden size-9 shrink-0 rounded-md object-contain dark:invert group-data-[collapsible=icon]:block" />
            <img src="/logo-full.png" alt="DropRSVP" className="ml-1 h-10 w-auto object-contain dark:invert group-data-[collapsible=icon]:hidden" />
        </>
    );
}
