import {
    Activity, Aperture, Atom, AudioLines, Beer, Bell, Bike, Bird, Book, BookOpen, Bot, Braces, Briefcase, Brush,
    Building2, Bus, Cake, Calendar, CalendarDays, Camera, Car, ChefHat, Church, Clapperboard, Code, Coffee, Coins,
    Compass, Cookie, Cpu, Crown, CupSoda, Database, Disc3, DollarSign, Drama, Drum, Droplets, Dumbbell, Film, Flag,
    FlaskConical, Flame, Flower2, Footprints, Gamepad2, Gem, Gift, Glasses, Globe, Goal, GraduationCap, Guitar,
    HandHeart, Handshake, Heart, HeartHandshake, HeartPulse, Headphones, IceCream, Image, Landmark, Laptop, Leaf,
    Library, Lightbulb, LineChart, Luggage, Map, MapPin, Martini, Medal, Megaphone, MessagesSquare, Mic, Mic2,
    Microscope, Moon, Mountain, Music, Music2, Paintbrush, Palette, PartyPopper, PenTool, Pencil, PieChart, Pizza,
    Plane, Presentation, Radio, Rocket, School, Ship, Shirt, ShoppingBag, Soup, Speaker, Sparkles, Sprout, Star, Sun,
    Tag, Target, Tent, Terminal, Ticket, Train, TreePine, TrendingUp, Trophy, UserPlus, Users, Users2, Utensils,
    UtensilsCrossed, Volleyball, Wand2, Waves, Wifi, Wine, Zap,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * Curated, searchable set of category icons (kebab-case name → component). Shared
 * by the admin icon picker AND the homepage category grid so what the superadmin
 * picks is exactly what visitors see. Keys are searchable tokens.
 */
export const CATEGORY_ICONS: Record<string, LucideIcon> = {
    // Music & audio
    music: Music, 'music-2': Music2, mic: Mic, podcast: Mic2, headphones: Headphones, guitar: Guitar,
    radio: Radio, speaker: Speaker, dj: Disc3, 'audio-lines': AudioLines, drum: Drum,
    // Arts & creative
    palette: Palette, brush: Brush, 'pen-tool': PenTool, paintbrush: Paintbrush, theatre: Drama, camera: Camera,
    film: Film, clapperboard: Clapperboard, image: Image, aperture: Aperture, sparkles: Sparkles, magic: Wand2,
    // Food & drink
    utensils: Utensils, 'utensils-crossed': UtensilsCrossed, coffee: Coffee, wine: Wine, beer: Beer, pizza: Pizza,
    'ice-cream': IceCream, cake: Cake, 'chef-hat': ChefHat, soda: CupSoda, cocktail: Martini, cookie: Cookie, soup: Soup,
    // Sports & fitness
    dumbbell: Dumbbell, bike: Bike, trophy: Trophy, medal: Medal, activity: Activity, footprints: Footprints,
    target: Target, flag: Flag, waves: Waves, mountain: Mountain, tent: Tent, volleyball: Volleyball, goal: Goal,
    // Tech & gaming
    cpu: Cpu, code: Code, laptop: Laptop, rocket: Rocket, robot: Bot, gamepad: Gamepad2, wifi: Wifi,
    terminal: Terminal, database: Database, braces: Braces,
    // Business & finance
    briefcase: Briefcase, 'trending-up': TrendingUp, presentation: Presentation, handshake: Handshake,
    building: Building2, landmark: Landmark, coins: Coins, 'line-chart': LineChart, 'pie-chart': PieChart, money: DollarSign,
    // Community & people
    users: Users, 'users-2': Users2, 'heart-handshake': HeartHandshake, party: PartyPopper, globe: Globe,
    messages: MessagesSquare, 'user-plus': UserPlus, 'hand-heart': HandHeart,
    // Wellness & nature
    heart: Heart, 'heart-pulse': HeartPulse, leaf: Leaf, flower: Flower2, sun: Sun, sprout: Sprout, tree: TreePine,
    droplets: Droplets, bird: Bird,
    // Education & science
    graduation: GraduationCap, 'book-open': BookOpen, book: Book, library: Library, lightbulb: Lightbulb,
    pencil: Pencil, microscope: Microscope, flask: FlaskConical, atom: Atom, school: School,
    // Travel
    plane: Plane, map: Map, 'map-pin': MapPin, compass: Compass, luggage: Luggage, ship: Ship, car: Car,
    train: Train, bus: Bus,
    // Events & general
    calendar: Calendar, 'calendar-days': CalendarDays, ticket: Ticket, star: Star, gift: Gift, megaphone: Megaphone,
    crown: Crown, flame: Flame, zap: Zap, tag: Tag, bell: Bell,
    // Lifestyle
    shirt: Shirt, shopping: ShoppingBag, gem: Gem, glasses: Glasses, moon: Moon, church: Church,
};

/** All icon names, for the picker's search list. */
export const CATEGORY_ICON_NAMES = Object.keys(CATEGORY_ICONS);

/** Resolve a stored icon name to a component, falling back to a neutral Tag. */
export function categoryIcon(name?: string | null): LucideIcon {
    return (name && CATEGORY_ICONS[name]) || Tag;
}
