/**
 * Keep a pasted page's CSS inside the box it is shown in.
 *
 * Pasted HTML is now written straight into the page (so its headings are real
 * headings to search engines and SEO tools), which means its <style> would
 * otherwise apply to the whole site — a `h1 { … }` or `body { … }` restyling
 * the header and footer. Every selector is rewritten to start from `scope`:
 *
 *   .card h2          → .scope .card h2
 *   html / :root      → .scope                    (custom properties, defaults)
 *   body.home .x      → .scope > .drop-body.home .x
 *   html.dark .x      → html.dark .scope .x       (the SITE's dark mode)
 *   :root[data-theme="dark"] .x → :root[data-theme="dark"] .scope .x
 *
 * Only the selectors are rewritten; every declaration block is copied exactly
 * as written. Letting the browser parse and re-serialise the stylesheet
 * (CSSOM) lost declarations: a shorthand built from var() — `background:
 * linear-gradient(var(--a), var(--b))` — cannot be expanded until it is used,
 * and came back out as empty longhands, which is how the pricing page's
 * gradient headline went invisible.
 *
 * @media, @supports, @container, @layer, @scope and @starting-style blocks are
 * walked into; @keyframes, @font-face, @page, @property and the like are
 * copied untouched. Comments are dropped.
 */
export function scopeCss(css: string, scope: string, body: string): string {
    if (css.trim() === '') {
        return '';
    }

    return scopeRules(css.replace(/\/\*[\s\S]*?\*\//g, ''), scope, body);
}

/** At-rules whose blocks hold ordinary style rules, which need scoping too. */
const GROUPS = ['media', 'supports', 'container', 'layer', 'scope', 'starting-style', 'document', '-moz-document'];

function scopeRules(text: string, scope: string, body: string): string {
    let out = '';
    let i = 0;

    while (i < text.length) {
        // The prelude: everything up to "{" (a block) or ";" (a statement).
        let j = i;
        let quote: string | null = null;
        let parens = 0;

        while (j < text.length) {
            const ch = text[j];

            if (quote) {
                j += ch === '\\' ? 2 : 1;

                if (ch === quote) {
                    quote = null;
                }

                continue;
            }

            if (ch === '"' || ch === "'") {
                quote = ch;
            } else if (ch === '(') {
                parens++;
            } else if (ch === ')') {
                parens--;
            } else if ((ch === '{' || ch === ';') && parens <= 0) {
                break;
            }

            j++;
        }

        const prelude = text.slice(i, j).trim();

        if (j >= text.length) {
            out += prelude;
            break;
        }

        if (text[j] === ';') {
            // @charset, "@layer a, b;" — statements, kept as they are.
            out += prelude ? `${prelude};` : '';
            i = j + 1;
            continue;
        }

        const end = closingBrace(text, j);
        const inner = text.slice(j + 1, end);

        if (prelude.startsWith('@')) {
            const name = prelude.slice(1).split(/[\s({]/)[0].toLowerCase();
            out += GROUPS.includes(name) ? `${prelude}{${scopeRules(inner, scope, body)}}` : `${prelude}{${inner}}`;
        } else if (prelude !== '') {
            // Nested rules inside the block (CSS nesting) are relative to this
            // selector, so scoping it scopes them too.
            out += `${scopeSelectorList(prelude, scope, body)}{${inner}}`;
        }

        i = end + 1;
    }

    return out;
}

/** The index of the "}" that closes the "{" at `open`, minding strings and nesting. */
function closingBrace(text: string, open: number): number {
    let depth = 0;
    let quote: string | null = null;

    for (let k = open; k < text.length; k++) {
        const ch = text[k];

        if (quote) {
            if (ch === '\\') {
                k++;
            } else if (ch === quote) {
                quote = null;
            }

            continue;
        }

        if (ch === '"' || ch === "'") {
            quote = ch;
        } else if (ch === '{') {
            depth++;
        } else if (ch === '}') {
            depth--;

            if (depth === 0) {
                return k;
            }
        }
    }

    return text.length;
}

/** Split "a, b:is(c, d)" on top-level commas only. */
function splitList(list: string): string[] {
    const out: string[] = [];
    let depth = 0;
    let quote: string | null = null;
    let start = 0;

    for (let i = 0; i < list.length; i++) {
        const ch = list[i];

        if (quote) {
            if (ch === quote && list[i - 1] !== '\\') {
                quote = null;
            }
        } else if (ch === '"' || ch === "'") {
            quote = ch;
        } else if (ch === '(' || ch === '[') {
            depth++;
        } else if (ch === ')' || ch === ']') {
            depth--;
        } else if (ch === ',' && depth === 0) {
            out.push(list.slice(start, i));
            start = i + 1;
        }
    }

    out.push(list.slice(start));

    return out;
}

/** The first compound selector and whatever follows it: "html.dark > .x" → ["html.dark", "> .x"]. */
function leading(selector: string): [string, string] {
    let depth = 0;
    let quote: string | null = null;

    for (let i = 0; i < selector.length; i++) {
        const ch = selector[i];

        if (quote) {
            if (ch === quote && selector[i - 1] !== '\\') {
                quote = null;
            }
        } else if (ch === '"' || ch === "'") {
            quote = ch;
        } else if (ch === '(' || ch === '[') {
            depth++;
        } else if (ch === ')' || ch === ']') {
            depth--;
        } else if (depth === 0 && /[\s>+~]/.test(ch)) {
            return [selector.slice(0, i), selector.slice(i).trim()];
        }
    }

    return [selector, ''];
}

function scopeSelectorList(list: string, scope: string, body: string): string {
    return splitList(list).map((one) => scopeSelector(one.trim(), scope, body)).filter(Boolean).join(', ');
}

function scopeSelector(selector: string, scope: string, body: string): string {
    if (selector === '') {
        return '';
    }

    let [first, rest] = leading(selector);
    let prefix = '';

    // The document root: its qualifiers (".dark", "[data-theme]") describe the
    // site's own <html>, so they stay on the real root, ahead of the scope.
    const root = first.match(/^(?:html|:root)(?=$|[.#:[])/i);

    if (root) {
        const qualifiers = first.slice(root[0].length);
        prefix = qualifiers ? `${root[0]}${qualifiers} ` : '';
        [first, rest] = rest === '' ? ['', ''] : leading(rest.replace(/^[>+~]\s*/, ''));
    }

    // <body>: the pasted page's body is a wrapper inside the scope.
    const isBody = first.match(/^body(?=$|[.#:[])/i);

    if (isBody) {
        return `${prefix}${body}${first.slice(isBody[0].length)}${rest ? ` ${rest}` : ''}`.trim();
    }

    if (first === '') {
        return `${prefix}${scope}`.trim();
    }

    return `${prefix}${scope} ${first}${rest ? ` ${rest}` : ''}`.trim();
}
