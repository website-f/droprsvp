import { useRef } from 'react';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { TagInput } from '@/components/ui/tag-input';

export interface SeoData {
    seo_title: string | null;
    meta_description: string | null;
    focus_keyphrase: string | null;
    meta_keywords: string | null;
    canonical_url: string | null;
    robots_index: boolean;
    robots_follow: boolean;
    og_title: string | null;
    og_description: string | null;
    og_image: string | null;
}

export interface SeoToken { token: string; label: string }

const field = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area = 'w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

function Count({ value, max }: { value: string | null; max: number }) {
    const len = (value ?? '').length;

    return <span className={`text-xs ${len > max ? 'text-destructive' : 'text-muted-foreground'}`}>{len}/{max}</span>;
}

/** Replace {tokens} in a preview string using the resolved values map. */
function resolve(text: string, values?: Record<string, string>): string {
    if (!values) {
        return text;
    }

    return text.replace(/\{[a-z_]+\}/g, (m) => (m in values ? values[m] : m));
}

/** Insert a token at the input's caret (or append), then restore the caret. */
function insertToken(el: HTMLInputElement | HTMLTextAreaElement | null, value: string | null, token: string, onChange: (v: string) => void) {
    const cur = value ?? '';

    if (!el) {
        onChange(cur + (cur && !cur.endsWith(' ') ? ' ' : '') + token);

        return;
    }

    const start = el.selectionStart ?? cur.length;
    const end = el.selectionEnd ?? cur.length;
    const next = cur.slice(0, start) + token + cur.slice(end);
    onChange(next);
    requestAnimationFrame(() => {
        el.focus();
        const pos = start + token.length;
        el.setSelectionRange(pos, pos);
    });
}

/** A row of clickable {token} chips that insert into the linked field. */
function TokenRow({ tokens, onInsert }: { tokens: SeoToken[]; onInsert: (token: string) => void }) {
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <span className="text-[11px] text-muted-foreground">Insert:</span>
            {tokens.map((t) => (
                <button
                    key={t.token}
                    type="button"
                    title={t.label}
                    onClick={() => onInsert(t.token)}
                    className="rounded-full border border-border bg-muted/40 px-2 py-0.5 text-[11px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    {t.token}
                </button>
            ))}
        </div>
    );
}

export function SeoFields({
    seo, onChange, slug, onSlug, fallbackTitle, baseUrl, tokens, tokenValues,
}: {
    seo: SeoData;
    onChange: (patch: Partial<SeoData>) => void;
    slug: string;
    onSlug: (v: string) => void;
    fallbackTitle: string;
    baseUrl: string;
    tokens?: SeoToken[];
    tokenValues?: Record<string, string>;
}) {
    const titleRef = useRef<HTMLInputElement>(null);
    const descRef = useRef<HTMLTextAreaElement>(null);
    const ogTitleRef = useRef<HTMLInputElement>(null);
    const ogDescRef = useRef<HTMLTextAreaElement>(null);
    const hasTokens = !!tokens && tokens.length > 0;

    // Preview substitutes tokens so the admin sees the real search snippet.
    const previewTitle = resolve(seo.seo_title || fallbackTitle || 'Untitled', tokenValues);
    const previewDesc = resolve(seo.meta_description || 'Add a meta description to control how this appears in search results.', tokenValues);
    const previewUrl = `${baseUrl}/${slug || 'slug'}`;

    return (
        <div className="rounded-xl border border-border bg-card p-5 shadow-sm">
            <h2 className="mb-4 text-sm font-semibold uppercase tracking-wide text-muted-foreground">SEO</h2>

            {/* Google snippet preview */}
            <div className="mb-5 rounded-lg border border-border bg-muted/40 p-4">
                <div className="truncate text-xs text-emerald-700 dark:text-emerald-400">{previewUrl}</div>
                <div className="mt-0.5 truncate text-base text-blue-700 dark:text-blue-400">{previewTitle}</div>
                <div className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{previewDesc}</div>
            </div>

            <div className="grid gap-4">
                <div className="grid gap-1.5">
                    <Label>URL slug</Label>
                    <div className="flex items-center gap-1 text-sm">
                        <span className="text-muted-foreground">{baseUrl}/</span>
                        <input className={field} value={slug} onChange={(e) => onSlug(e.target.value)} placeholder="auto from title" />
                    </div>
                </div>
                <div className="grid gap-1.5">
                    <div className="flex items-center justify-between"><Label>SEO title</Label><Count value={seo.seo_title} max={60} /></div>
                    <input ref={titleRef} className={field} value={seo.seo_title ?? ''} onChange={(e) => onChange({ seo_title: e.target.value })} placeholder={fallbackTitle} />
                    {hasTokens && <TokenRow tokens={tokens!} onInsert={(t) => insertToken(titleRef.current, seo.seo_title, t, (v) => onChange({ seo_title: v }))} />}
                </div>
                <div className="grid gap-1.5">
                    <div className="flex items-center justify-between"><Label>Meta description</Label><Count value={seo.meta_description} max={155} /></div>
                    <textarea ref={descRef} rows={3} className={area} value={seo.meta_description ?? ''} onChange={(e) => onChange({ meta_description: e.target.value })} />
                    {hasTokens && <TokenRow tokens={tokens!} onInsert={(t) => insertToken(descRef.current, seo.meta_description, t, (v) => onChange({ meta_description: v }))} />}
                </div>
                <div className="grid gap-1.5">
                    <Label>Focus keyphrase</Label>
                    <input className={field} value={seo.focus_keyphrase ?? ''} onChange={(e) => onChange({ focus_keyphrase: e.target.value })} />
                </div>
                <div className="grid gap-1.5">
                    <Label>Keywords</Label>
                    <TagInput value={seo.meta_keywords ?? ''} onChange={(v) => onChange({ meta_keywords: v })} placeholder="Type a keyword, press Enter…" />
                    <p className="text-xs text-muted-foreground">Type each keyword and press Enter. Rendered as the <code>meta keywords</code> tag.</p>
                </div>

                <div className="flex flex-wrap gap-6">
                    <span className="flex items-center gap-2 text-sm"><Switch checked={seo.robots_index} onCheckedChange={(v) => onChange({ robots_index: v })} aria-label="Allow indexing" /> Allow indexing</span>
                    <span className="flex items-center gap-2 text-sm"><Switch checked={seo.robots_follow} onCheckedChange={(v) => onChange({ robots_follow: v })} aria-label="Follow links" /> Follow links</span>
                </div>

                <details className="rounded-lg border border-border p-3">
                    <summary className="cursor-pointer text-sm font-medium">Social &amp; advanced</summary>
                    <div className="mt-3 grid gap-4">
                        <div className="grid gap-1.5"><Label>Canonical URL</Label><input className={field} value={seo.canonical_url ?? ''} onChange={(e) => onChange({ canonical_url: e.target.value })} placeholder={previewUrl} /></div>
                        <div className="grid gap-1.5">
                            <Label>Social title (OG)</Label>
                            <input ref={ogTitleRef} className={field} value={seo.og_title ?? ''} onChange={(e) => onChange({ og_title: e.target.value })} />
                            {hasTokens && <TokenRow tokens={tokens!} onInsert={(t) => insertToken(ogTitleRef.current, seo.og_title, t, (v) => onChange({ og_title: v }))} />}
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Social description (OG)</Label>
                            <textarea ref={ogDescRef} rows={2} className={area} value={seo.og_description ?? ''} onChange={(e) => onChange({ og_description: e.target.value })} />
                            {hasTokens && <TokenRow tokens={tokens!} onInsert={(t) => insertToken(ogDescRef.current, seo.og_description, t, (v) => onChange({ og_description: v }))} />}
                        </div>
                        <div className="grid gap-1.5"><Label>Social image URL (OG)</Label><input className={field} value={seo.og_image ?? ''} onChange={(e) => onChange({ og_image: e.target.value })} placeholder="https://…" /></div>
                    </div>
                </details>
            </div>
        </div>
    );
}
