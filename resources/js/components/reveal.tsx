import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';

/**
 * Fades/slides its children in the first time they scroll into view. SSR-safe:
 * the content is always rendered, the observer only runs on the client, and the
 * actual motion is gated behind `prefers-reduced-motion` in CSS.
 */
export function Reveal({
    children,
    delay = 0,
    className,
}: {
    children: ReactNode;
    delay?: number;
    className?: string;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        if (typeof IntersectionObserver === 'undefined') {
            setVisible(true);
            return;
        }
        const io = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setVisible(true);
                    io.disconnect();
                }
            },
            { threshold: 0.12, rootMargin: '0px 0px -8% 0px' },
        );
        io.observe(el);
        return () => io.disconnect();
    }, []);

    return (
        <div
            ref={ref}
            data-reveal
            className={`${visible ? 'is-visible ' : ''}${className ?? ''}`}
            style={{ '--reveal-delay': `${delay}ms` } as CSSProperties}
        >
            {children}
        </div>
    );
}
