import { X } from 'lucide-react';
import { type KeyboardEvent, useState } from 'react';

/**
 * Tag/keyword input: type a value and press Enter (or comma) to add it as a chip.
 * The value in/out is a comma-separated string, so backends storing a plain
 * "a, b, c" string need no changes.
 */
export function TagInput({ value, onChange, placeholder, id }: { value: string; onChange: (v: string) => void; placeholder?: string; id?: string }) {
    const tags = value.split(',').map((t) => t.trim()).filter(Boolean);
    const [input, setInput] = useState('');

    const commit = (raw: string) => {
        const t = raw.trim().replace(/,+$/, '').trim();
        if (t && ! tags.includes(t)) {
            onChange([...tags, t].join(', '));
        }
        setInput('');
    };
    const remove = (t: string) => onChange(tags.filter((x) => x !== t).join(', '));

    const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            commit(input);
        } else if (e.key === 'Backspace' && input === '' && tags.length > 0) {
            remove(tags[tags.length - 1]);
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-1.5 rounded-lg border border-input bg-card px-2 py-1.5 transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/20">
            {tags.map((t) => (
                <span key={t} className="inline-flex items-center gap-1 rounded-md bg-secondary px-2 py-0.5 text-xs font-medium">
                    {t}
                    <button type="button" onClick={() => remove(t)} aria-label={`Remove ${t}`} className="text-muted-foreground hover:text-foreground"><X className="size-3" /></button>
                </span>
            ))}
            <input
                id={id}
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={onKey}
                onBlur={() => commit(input)}
                placeholder={tags.length ? '' : placeholder}
                className="min-w-[8rem] flex-1 bg-transparent px-1 py-1 text-sm outline-none"
            />
        </div>
    );
}
