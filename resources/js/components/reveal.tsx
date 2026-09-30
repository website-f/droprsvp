import { useEffect, useRef, useState   } from 'react';
import type {CSSProperties, ReactNode} from 'react';

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
    // Without IntersectionObserver there is nothing to wait for, so start
    // visible rather than flipping the state from inside the effect — which
    // costs a second render and is what react-hooks/set-state-in-effect is
    // warning about.
    const [visible, setVisible] = useState(() => typeof IntersectionObserver === 'undefined');

    useEffect(() => {
        const el = ref.current;

        if (!el || typeof IntersectionObserver === 'undefined') {
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
