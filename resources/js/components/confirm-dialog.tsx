import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';

export interface ConfirmOptions {
    title?: string;
    description?: ReactNode;
    confirmText?: string;
    cancelText?: string;
    destructive?: boolean;
}

type ConfirmFn = (opts?: ConfirmOptions) => Promise<boolean>;

const ConfirmContext = createContext<ConfirmFn | null>(null);

/**
 * App-wide themed replacement for window.confirm(). Wrap the app once, then
 * `const confirm = useConfirm(); if (await confirm({...})) { ... }`.
 */
export function ConfirmProvider({ children }: { children: ReactNode }) {
    const [open, setOpen] = useState(false);
    const [opts, setOpts] = useState<ConfirmOptions>({});
    const resolver = useRef<((v: boolean) => void) | null>(null);

    const confirm = useCallback<ConfirmFn>((o = {}) => {
        setOpts(o);
        setOpen(true);
        return new Promise<boolean>((resolve) => { resolver.current = resolve; });
    }, []);

    const settle = (value: boolean) => {
        setOpen(false);
        resolver.current?.(value);
        resolver.current = null;
    };

    return (
        <ConfirmContext.Provider value={confirm}>
            {children}
            <Dialog open={open} onOpenChange={(o) => { if (!o) settle(false); }}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{opts.title ?? 'Are you sure?'}</DialogTitle>
                        {opts.description && <DialogDescription>{opts.description}</DialogDescription>}
                    </DialogHeader>
                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button variant="outline" onClick={() => settle(false)}>{opts.cancelText ?? 'Cancel'}</Button>
                        <Button variant={opts.destructive ? 'destructive' : 'default'} onClick={() => settle(true)} autoFocus>
                            {opts.confirmText ?? 'Confirm'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </ConfirmContext.Provider>
    );
}

export function useConfirm(): ConfirmFn {
    const ctx = useContext(ConfirmContext);
    if (!ctx) throw new Error('useConfirm must be used within <ConfirmProvider>');
    return ctx;
}
