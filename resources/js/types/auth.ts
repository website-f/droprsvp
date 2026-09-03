export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    is_superadmin?: boolean;
    is_organizer?: boolean;
    is_premium?: boolean;
    /** Superadmin OR staff — controls whether the admin nav shows at all. */
    is_admin?: boolean;
    /** Admin section keys this user may access (staff = granted subset; superadmin = all). */
    admin_sections?: string[];
    must_set_password?: boolean;
    unread_notifications?: number;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
