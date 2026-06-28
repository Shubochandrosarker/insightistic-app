"use client";
import {
  ResponsiveContainer, AreaChart, Area, LineChart, Line, BarChart, Bar,
  PieChart, Pie, Cell, XAxis, YAxis, CartesianGrid, Tooltip,
} from "recharts";

const BRAND = "#00C04B";
const BRAND2 = "#00D084";
const VIOLET = "#6C5CE7";

const tipStyle = {
  background: "var(--ins-card)",
  border: "1px solid var(--ins-line)",
  borderRadius: 10,
  color: "var(--ins-fg)",
  fontSize: 12,
  boxShadow: "0 8px 24px -12px rgba(11,17,16,0.2)",
};

/** Tiny sparkline for KPI cards — no axes, just the trend. */
export function Sparkline({ data, dataKey = "v", color = BRAND, height = 40 }:
  { data: any[]; dataKey?: string; color?: string; height?: number }) {
  const id = `spark-${dataKey}-${color.replace("#", "")}`;
  return (
    <ResponsiveContainer width="100%" height={height}>
      <AreaChart data={data} margin={{ top: 4, right: 0, bottom: 0, left: 0 }}>
        <defs>
          <linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={color} stopOpacity={0.25} />
            <stop offset="100%" stopColor={color} stopOpacity={0} />
          </linearGradient>
        </defs>
        <Area type="monotone" dataKey={dataKey} stroke={color} strokeWidth={2}
          fill={`url(#${id})`} dot={false} isAnimationActive={false} />
      </AreaChart>
    </ResponsiveContainer>
  );
}

/** Big revenue/area trend chart. */
export function AreaTrend({ data, dataKey = "revenue", color = BRAND, height = 280 }:
  { data: any[]; dataKey?: string; color?: string; height?: number }) {
  return (
    <ResponsiveContainer width="100%" height={height}>
      <AreaChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
        <defs>
          <linearGradient id="area-trend" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={color} stopOpacity={0.28} />
            <stop offset="100%" stopColor={color} stopOpacity={0} />
          </linearGradient>
        </defs>
        <CartesianGrid vertical={false} stroke="var(--ins-line)" />
        <XAxis dataKey="date" tick={{ fill: "var(--ins-muted)", fontSize: 11 }}
          axisLine={false} tickLine={false} minTickGap={28} />
        <YAxis tick={{ fill: "var(--ins-muted)", fontSize: 11 }} axisLine={false}
          tickLine={false} width={44} />
        <Tooltip contentStyle={tipStyle} />
        <Area type="monotone" dataKey={dataKey} stroke={color} strokeWidth={2.4}
          fill="url(#area-trend)" dot={false} />
      </AreaChart>
    </ResponsiveContainer>
  );
}

export function Bars({ data, dataKey = "v", color = BRAND, height = 240 }:
  { data: any[]; dataKey?: string; color?: string; height?: number }) {
  return (
    <ResponsiveContainer width="100%" height={height}>
      <BarChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
        <defs>
          <linearGradient id="bar-grad" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={BRAND2} />
            <stop offset="100%" stopColor={BRAND} />
          </linearGradient>
        </defs>
        <CartesianGrid vertical={false} stroke="var(--ins-line)" />
        <XAxis dataKey="label" tick={{ fill: "var(--ins-muted)", fontSize: 11 }}
          axisLine={false} tickLine={false} />
        <YAxis tick={{ fill: "var(--ins-muted)", fontSize: 11 }} axisLine={false} tickLine={false} width={44} />
        <Tooltip contentStyle={tipStyle} cursor={{ fill: "rgba(0,192,75,0.06)" }} />
        <Bar dataKey={dataKey} fill="url(#bar-grad)" radius={[6, 6, 0, 0]} maxBarSize={42} />
      </BarChart>
    </ResponsiveContainer>
  );
}

export function LineTrend({ data, dataKey = "v", color = BRAND, height = 240 }:
  { data: any[]; dataKey?: string; color?: string; height?: number }) {
  return (
    <ResponsiveContainer width="100%" height={height}>
      <LineChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid vertical={false} stroke="var(--ins-line)" />
        <XAxis dataKey="date" tick={{ fill: "var(--ins-muted)", fontSize: 11 }} axisLine={false} tickLine={false} minTickGap={28} />
        <YAxis tick={{ fill: "var(--ins-muted)", fontSize: 11 }} axisLine={false} tickLine={false} width={44} />
        <Tooltip contentStyle={tipStyle} />
        <Line type="monotone" dataKey={dataKey} stroke={color} strokeWidth={2.4} dot={false} />
      </LineChart>
    </ResponsiveContainer>
  );
}

const DONUT_COLORS = [BRAND, BRAND2, "#F6A609", VIOLET, "#9FB4AD"];

export function Donut({ data, centerLabel, centerValue, height = 220 }:
  { data: { name: string; value: number }[]; centerLabel?: string; centerValue?: string; height?: number }) {
  return (
    <div className="relative">
      <ResponsiveContainer width="100%" height={height}>
        <PieChart>
          <Pie data={data} dataKey="value" nameKey="name" cx="50%" cy="50%"
            innerRadius={62} outerRadius={88} paddingAngle={2} stroke="none">
            {data.map((_, i) => <Cell key={i} fill={DONUT_COLORS[i % DONUT_COLORS.length]} />)}
          </Pie>
          <Tooltip contentStyle={tipStyle} />
        </PieChart>
      </ResponsiveContainer>
      {(centerValue || centerLabel) && (
        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
          {centerValue && <div className="text-2xl font-bold text-fg">{centerValue}</div>}
          {centerLabel && <div className="text-xs text-muted">{centerLabel}</div>}
        </div>
      )}
    </div>
  );
}

export const DONUT_LEGEND = DONUT_COLORS;
