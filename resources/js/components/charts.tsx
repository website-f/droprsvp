import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

/**
 * Themed, responsive Recharts wrappers used across the organizer + superadmin
 * analytics pages. Built-in tooltips give interactive hover; ResponsiveContainer
 * makes every chart fluid. ('use no memo' keeps the React Compiler from touching
 * Recharts' internal element cloning.)
 */

export const PALETTE = ['#6c63ff', '#f5a524', '#2ec4b6', '#ff6584', '#3b82f6', '#a855f7', '#22c55e', '#f97316'];

const tooltipStyle = { background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, fontSize: 12, color: 'var(--foreground)' } as const;
const axisTick = { fill: 'var(--muted-foreground)', fontSize: 11 } as const;

interface Point { date: string; impressions: number; clicks: number }

/** Impressions + clicks over time. */
export function TrendChart({ data, height = 260 }: { data: Point[]; height?: number }) {
    'use no memo';

    return (
        <ResponsiveContainer width="100%" height={height}>
            <AreaChart data={data} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
                <defs>
                    <linearGradient id="gImp" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor={PALETTE[0]} stopOpacity={0.35} /><stop offset="100%" stopColor={PALETTE[0]} stopOpacity={0} /></linearGradient>
                    <linearGradient id="gClk" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor={PALETTE[1]} stopOpacity={0.35} /><stop offset="100%" stopColor={PALETTE[1]} stopOpacity={0} /></linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="date" tick={axisTick} tickLine={false} axisLine={false} minTickGap={24} />
                <YAxis tick={axisTick} tickLine={false} axisLine={false} allowDecimals={false} width={36} />
                <Tooltip contentStyle={tooltipStyle} labelStyle={{ color: 'var(--muted-foreground)' }} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <Area type="monotone" dataKey="impressions" name="Impressions" stroke={PALETTE[0]} strokeWidth={2} fill="url(#gImp)" />
                <Area type="monotone" dataKey="clicks" name="Clicks" stroke={PALETTE[1]} strokeWidth={2} fill="url(#gClk)" />
            </AreaChart>
        </ResponsiveContainer>
    );
}

interface Slice { name: string; value: number }

/** Donut for categorical breakdowns (gender, source, …). */
export function DonutChart({ data, height = 240 }: { data: Slice[]; height?: number }) {
    'use no memo';

    if (data.length === 0) {
return <Empty height={height} />;
}

    return (
        <ResponsiveContainer width="100%" height={height}>
            <PieChart>
                <Pie data={data} dataKey="value" nameKey="name" innerRadius="55%" outerRadius="80%" paddingAngle={2} stroke="var(--card)">
                    {data.map((_, i) => <Cell key={i} fill={PALETTE[i % PALETTE.length]} />)}
                </Pie>
                <Tooltip contentStyle={tooltipStyle} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
            </PieChart>
        </ResponsiveContainer>
    );
}

/** Horizontal bars for ranked breakdowns (age bands, top cities). */
export function BarsChart({ data, height = 240, color = PALETTE[0] }: { data: Slice[]; height?: number; color?: string }) {
    'use no memo';

    if (data.length === 0) {
return <Empty height={height} />;
}

    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 12, left: 8, bottom: 4 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" horizontal={false} />
                <XAxis type="number" tick={axisTick} tickLine={false} axisLine={false} allowDecimals={false} />
                <YAxis type="category" dataKey="name" tick={axisTick} tickLine={false} axisLine={false} width={96} />
                <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'var(--muted)', opacity: 0.4 }} />
                <Bar dataKey="value" fill={color} radius={[0, 6, 6, 0]} barSize={18} />
            </BarChart>
        </ResponsiveContainer>
    );
}

/** Revenue per day (vertical bars). */
export function RevenueBars({ data, height = 260 }: { data: { date: string; revenue: number }[]; height?: number }) {
    'use no memo';

    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart data={data} margin={{ top: 8, right: 8, left: -4, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="date" tick={axisTick} tickLine={false} axisLine={false} minTickGap={24} />
                <YAxis tick={axisTick} tickLine={false} axisLine={false} width={52} />
                <Tooltip contentStyle={tooltipStyle} labelStyle={{ color: 'var(--muted-foreground)' }} formatter={(v) => [`RM ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}`, 'Revenue']} cursor={{ fill: 'var(--muted)', opacity: 0.4 }} />
                <Bar dataKey="revenue" fill={PALETTE[6]} radius={[6, 6, 0, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}

function Empty({ height }: { height: number }) {
    return <div className="flex items-center justify-center text-sm text-muted-foreground" style={{ height }}>No data yet</div>;
}
