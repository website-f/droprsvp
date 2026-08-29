import type { Auth } from '@/types/auth';
import type { Branding, FooterConfig, PublicNavItem } from '@/types/navigation';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            nav: PublicNavItem[];
            footer: FooterConfig;
            branding: Branding;
            [key: string]: unknown;
        };
    }
}
