"use client";
import {
  ResponsiveContainer, LineChart, Line, BarChart, Bar, XAxis, YAxis,
  CartesianGrid, Tooltip, Legend,
} from "recharts";

const grid = "#1b2433";
const axis = { stroke: "#8A95A8", fontSize: 11 };

export function RevenueLine({ data }: { data: any[] }) {
  return (
    <ResponsiveContainer width="100%" height={220}>
      <LineChart data={data}>
        <CartesianGrid stroke={grid} />
        <XAxis dataKey="date" tick={axis} />
        <YAxis tick={axis} />
        <Tooltip contentStyle={{ background: "#131925", border: "1px solid #222C3C", color: "#fff" }} />
        <Line type="monotone" dataKey="revenue" stroke="#3B82F6" strokeWidth={2} dot={false} />
      </LineChart>
    </ResponsiveContainer>
  );
}

export function OrdersBars({ data }: { data: any[] }) {
  return (
    <ResponsiveContainer width="100%" height={220}>
      <BarChart data={data}>
        <CartesianGrid stroke={grid} />
        <XAxis dataKey="date" tick={axis} />
        <YAxis tick={axis} />
        <Tooltip contentStyle={{ background: "#131925", border: "1px solid #222C3C", color: "#fff" }} />
        <Legend />
        <Bar dataKey="orders" fill="#16A34A" />
        <Bar dataKey="failed" fill="#DC2626" />
      </BarChart>
    </ResponsiveContainer>
  );
}
