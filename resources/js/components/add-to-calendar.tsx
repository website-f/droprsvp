import { CalendarPlus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';

interface Props {
    googleUrl: string;
    icsUrl: string;
    label?: string;
    variant?: 'default' | 'outline' | 'secondary' | 'ghost';
    size?: 'default' | 'sm' | 'lg' | 'icon';
    className?: string;
}

/** A compact "Add to calendar" menu: Google (link) + Apple/Outlook (.ics download). */
export function AddToCalendar({ googleUrl, icsUrl, label = 'Add to calendar', variant = 'outline', size = 'sm', className }: Props) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant={variant} size={size} className={className}>
                    <CalendarPlus className="size-4" /> {label}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuItem asChild>
                    <a href={googleUrl} target="_blank" rel="noopener noreferrer">Google Calendar</a>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a href={icsUrl}>Apple Calendar</a>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a href={icsUrl}>Outlook / other</a>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
