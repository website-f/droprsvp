import { useLayoutEffect, useRef } from 'react';
import { cn } from '@/lib/utils';

/**
 * A data table that works on a phone.
 *
 * Wide admin and host tables used to sit in an `overflow-x-auto` box. On a
 * phone that box has no visible scrollbar, so a table wider than the screen
 * looked cut off — and inside a grid column it really was cut off, because the
 * column grew to the table's width and the layout clips horizontal overflow.
 * Narrower tables squeezed instead, into columns three words wide.
 *
 * Below the `md` breakpoint each row becomes a card: the first cell is its
 * title, and every other cell is a "Label … value" line, labelled with its
 * column's header. From `md` up it is the ordinary table, still scrollable.
 * The CSS lives in app.css under `.rtable`.
 *
 * The labels are copied from the header row onto each cell as `data-label`, so
 * no table needs its column names written twice. A MutationObserver keeps them
 * right as rows change (paging, filtering, live search). Cells spanning several
 * columns — empty states, totals — get no label.
 */
export function ResponsiveTable({ className, children }: { className?: string; children: React.ReactNode }) {
    const ref = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        const root = ref.current;

        if (!root) {
            return;
        }

        const label = () => {
            root.querySelectorAll('table').forEach((table) => {
                const heads = Array.from(table.querySelectorAll('thead tr:last-child th')).map((th) => th.textContent?.trim() ?? '');

                table.querySelectorAll('tbody tr, tfoot tr').forEach((tr) => {
                    let col = 0;

                    Array.from(tr.children).forEach((cell) => {
                        const span = (cell as HTMLTableCellElement).colSpan || 1;
                        const text = span === 1 ? (heads[col] ?? '') : '';

                        // Attribute writes are not observed, so this cannot loop.
                        if (cell.getAttribute('data-label') !== text) {
                            cell.setAttribute('data-label', text);
                        }

                        col += span;
                    });
                });
            });
        };

        label();

        const observer = new MutationObserver(label);
        observer.observe(root, { childList: true, subtree: true, characterData: true });

        return () => observer.disconnect();
    }, []);

    return (
        <div ref={ref} className={cn('rtable overflow-x-auto', className)}>
            {children}
        </div>
    );
}
