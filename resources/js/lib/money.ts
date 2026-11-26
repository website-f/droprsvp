/**
 * Money labels. Mirrors CountryRates::symbol() on the server: ringgit shows
 * as "RM", Singapore dollars as "S$", and any other currency as its code.
 * Amounts are in the order's / event's own currency — never converted.
 */
const SYMBOLS: Record<string, string> = {
    MYR: 'RM',
    SGD: 'S$',
    USD: 'US$',
    AUD: 'A$',
    EUR: '€',
    GBP: '£',
    IDR: 'Rp',
    THB: '฿',
    PHP: '₱',
    VND: '₫',
    JPY: '¥',
    INR: '₹',
};

export function currencySymbol(currency?: string | null): string {
    const code = (currency || 'MYR').toUpperCase();

    return SYMBOLS[code] ?? code;
}

/** "RM 25.00", "S$ 25.00". */
export function money(amount: number | string | null | undefined, currency?: string | null): string {
    const n = Number(amount ?? 0);

    return `${currencySymbol(currency)} ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
