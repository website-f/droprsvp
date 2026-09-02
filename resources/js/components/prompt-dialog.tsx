import { createContext, useCallback, useContext, useRef, useState  } from 'react';
import type {ReactNode} from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

export interface PromptOptions {
    title?: string;
    description?: ReactNode;
    label?: string;
    placeholder?: string;
    defaultValue?: string;
    confirmText?: string;
    cancelText?: string;
}

type PromptFn = (opts?: PromptOptions) => Promise<string | null>;

const PromptContext = createContext<PromptFn | null>(null);

/**
 * App-wide themed replacement for window.prompt(). Wrap the app once, then
 * `const prompt = usePrompt(); const name = await prompt({...})` — resolves to
 * the trimmed value, or null if cancelled.
 */
export function PromptProvider({ children }: { children: ReactNode }) {
    const [open, setOpen] = useState(false);
    const [opts, setOpts] = useState<PromptOptions>({});
    const [value, setValue] = useState('');
    const resolver = useRef<((v: string | null) => void) | null>(null);

    const prompt = useCallback<PromptFn>((o = {}) => {
        setOpts(o);
        setValue(o.defaultValue ?? '');
        setOpen(true);

        return new Promise<string | null>((resolve) => {
 resolver.current = resolve; 
});
    }, []);

    const settle = (v: string | null) => {
        setOpen(false);
        resolver.current?.(v);
        resolver.current = null;
    };

    return (
        <PromptContext.Provider value={prompt}>
            {children}
            <Dialog open={open} onOpenChange={(o) => {
 if (!o) {
settle(null);
} 
}}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{opts.title ?? 'Enter a value'}</DialogTitle>
                        {opts.description && <DialogDescription>{opts.description}</DialogDescription>}
                    </DialogHeader>
                    <form onSubmit={(e) => {
 e.preventDefault();

 if (value.trim()) {
settle(value.trim());
} 
}} className="grid gap-2">
                        {opts.label && <label className="text-sm font-medium">{opts.label}</label>}
                        <input
                            autoFocus
                            value={value}
                            onChange={(e) => setValue(e.target.value)}
                            placeholder={opts.placeholder}
                            className="h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                        />
                        <DialogFooter className="gap-2 sm:gap-2">
                            <Button type="button" variant="outline" onClick={() => settle(null)}>{opts.cancelText ?? 'Cancel'}</Button>
                            <Button type="submit" disabled={!value.trim()}>{opts.confirmText ?? 'OK'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </PromptContext.Provider>
    );
}

export function usePrompt(): PromptFn {
    const ctx = useContext(PromptContext);

    if (!ctx) {
        throw new Error('usePrompt must be used within <PromptProvider>');
    }

    return ctx;
}
