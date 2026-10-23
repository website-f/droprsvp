import { useId, useMemo } from 'react';
import { InlineHtml, planInline } from '@/components/inline-html';
import { contentClass } from '@/components/rich-editor';
import { SandboxedHtml, hasScripts } from '@/components/sandboxed-html';
import { ShadowHtml, disarm } from '@/components/shadow-html';
import { cn } from '@/lib/utils';

/**
 * Pasted HTML comes in two kinds, and they want opposite treatment:
 *
 *   "partial" — content: headings, paragraphs, lists, a table, with no styling
 *               of its own. It should look like the rest of the site, so it
 *               gets the site's article typography in a readable column (the
 *               same look as blog posts and the legal pages).
 *   "full"    — a designed page with its own <style>/stylesheets. Its CSS is
 *               the design, so it is shown exactly as written, with that CSS
 *               scoped to its own box (see InlineHtml).
 *
 * Either way it is written into the page itself, so its headings are the
 * page's headings to search engines and SEO tools. Its scripts run there too
 * when a superadmin saved the page last; otherwise the page is shown in a
 * sealed frame where they can run without reaching the site (SandboxedHtml).
 *
 * Shown the "full" way, a partial paste had nothing styling it at all — bare
 * browser defaults, no spacing, borderless tables — which is what the pricing
 * page looked like.
 */
export type HtmlLayout = 'content' | 'full';
/** A stored choice, or "auto" to decide from the HTML itself. */
export type HtmlLayoutChoice = HtmlLayout | 'auto';

/**
 * Does this HTML bring its own design? A whole document, or any stylesheet.
 * Comments are ignored: the pricing page's notes said "paste into the page
 * <head>", and that alone had it treated as a full page.
 */
export function detectLayout(html: string): HtmlLayout {
    const markup = html.replace(/<!--[\s\S]*?-->/g, '');

    return /<\s*(html|head|body|style)\b|<\s*link\b[^>]*rel\s*=\s*["']?stylesheet/i.test(markup) ? 'full' : 'content';
}

export function resolveLayout(html: string, choice?: HtmlLayoutChoice | null): HtmlLayout {
    return choice === 'content' || choice === 'full' ? choice : detectLayout(html);
}

/** The body of the HTML, with anything that could run removed — for writing into the page. */
export function cleanFragment(html: string): string {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    disarm(doc);
    doc.querySelectorAll('style, link').forEach((node) => node.remove());

    return doc.body.innerHTML;
}

/**
 * Site typography for partial HTML. The first element loses its top margin so
 * a pasted page that starts with its own <h1> does not open on a gap.
 */
export const partialClass = cn(contentClass, '[&>*:first-child]:mt-0');

/** Pasted HTML on a public page, a builder block, or the page preview. */
export function HtmlContent({ html, layout = 'auto', trusted = false, className }: {
    html: string;
    layout?: HtmlLayoutChoice | null;
    /** Last saved by a superadmin: its scripts may run in the page itself. */
    trusted?: boolean;
    className?: string;
}) {
    const resolved = resolveLayout(html, layout);
    const scripted = useMemo(() => hasScripts(html), [html]);
    const runScripts = scripted && trusted;
    // A class unique to this box, for scoping its CSS.
    const scope = `dh-${useId().replace(/[^a-z0-9]/gi, '')}`;

    const plan = useMemo(
        () => (resolved === 'full' || runScripts ? planInline(html, `.${scope}`, runScripts) : null),
        [html, resolved, runScripts, scope],
    );
    const fragment = useMemo(() => (resolved === 'content' && !scripted ? cleanFragment(html) : ''), [html, resolved, scripted]);

    // Scripts from a page not saved by a superadmin — or ones relying on a
    // stylesheet that cannot be scoped — run in the sealed frame instead.
    if (scripted && (!runScripts || !plan)) {
        return <SandboxedHtml html={html} layout={resolved} contentClass={partialClass} className={className} />;
    }

    if (plan) {
        return <InlineHtml plan={plan} scope={scope} layout={resolved} contentClass={partialClass} className={className} />;
    }

    // A full page linking a stylesheet hosted elsewhere: its CSS cannot be
    // scoped, so it keeps the shadow root that walls it off instead.
    if (resolved === 'full') {
        return <ShadowHtml html={html} className={className} />;
    }

    return <article className={cn(partialClass, className)} dangerouslySetInnerHTML={{ __html: fragment }} />;
}
