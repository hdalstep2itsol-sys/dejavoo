"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { getAdminDashboard } from "@/dashboards/api";
import { displayUnits, titleCase } from "@/dashboards/format";
import { LoadProgress, OperationalBadge } from "@/dashboards/LoadProgress";
import type { AdminDashboardData } from "@/dashboards/types";
import { requestErrorMessage } from "@/locations/errors";

const emptyDashboard: AdminDashboardData = {
  summary: {
    active_locations: 0,
    ready_loads: 0,
    awaiting_warehouse_count: 0,
    open_unclaimed_active_loads: 0,
  },
  locations: [],
};

export default function AdminPage() {
  const [dashboard, setDashboard] = useState(emptyDashboard);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;
    void getAdminDashboard()
      .then((data) => {
        if (!cancelled) setDashboard(data);
      })
      .catch((caught) => {
        if (!cancelled) setError(requestErrorMessage(caught));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const cards = [
    ["Active Locations", dashboard.summary.active_locations, "#active-locations"],
    ["Ready Loads", dashboard.summary.ready_loads, "/admin/routes?status=ready"],
    ["Awaiting Warehouse Count", dashboard.summary.awaiting_warehouse_count, "/admin/warehouse-pending"],
    ["Open / Unclaimed Active Loads", dashboard.summary.open_unclaimed_active_loads, "/admin/routes?assignment=unclaimed"],
  ] as const;

  return (
    <main className="min-h-screen px-4 py-8 sm:px-6 lg:px-8">
      <div className="mx-auto max-w-7xl">
        <p className="text-sm font-semibold uppercase tracking-[0.16em] text-cyan-400">Operations</p>
        <h1 className="mt-2 text-3xl font-semibold">Owner / Admin Dashboard</h1>
        <p className="mt-2 text-slate-400">Current load readiness across active locations.</p>

        {error && <p role="alert" className="mt-6 rounded-lg bg-red-950 px-4 py-3 text-red-200">{error}</p>}

        <section className="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {cards.map(([label, count, href]) => (
            <Link key={label} href={href} className="rounded-2xl border border-slate-800 bg-slate-900 p-5 transition hover:border-cyan-700">
              <p className="text-sm text-slate-400">{label}</p>
              <p className="mt-2 text-3xl font-semibold">{loading ? "—" : count}</p>
            </Link>
          ))}
        </section>

        <section id="active-locations" className="mt-8 overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">
          <div className="border-b border-slate-800 px-5 py-4"><h2 className="text-xl font-semibold">Active Locations</h2></div>
          {loading ? (
            <p className="p-8 text-center text-slate-400">Loading operations...</p>
          ) : dashboard.locations.length === 0 ? (
            <p className="p-8 text-center text-slate-400">No active locations.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[1050px] text-left text-sm">
                <thead className="bg-slate-950/60 text-slate-400"><tr>{["Location", "Operational", "Threshold", "Progress", "Status", "Route", "Driver", "Forecast", ""].map((heading) => <th key={heading} className="px-4 py-3 font-medium">{heading}</th>)}</tr></thead>
                <tbody className="divide-y divide-slate-800">
                  {dashboard.locations.map((location) => {
                    const load = location.active_load;
                    const driver = load?.committed_driver ?? location.dedicated_driver;
                    return (
                      <tr key={location.id}>
                        <td className="px-4 py-4 font-semibold text-white">{location.name}</td>
                        <td className="px-4 py-4">{load ? displayUnits(load.operational_units) : "No active load"}</td>
                        <td className="px-4 py-4">{displayUnits(location.haul_threshold)}</td>
                        <td className="px-4 py-4">{load ? <LoadProgress percentage={load.progress_percentage} /> : "—"}</td>
                        <td className="px-4 py-4">{load ? <OperationalBadge status={load.operational_status} /> : "—"}</td>
                        <td className="px-4 py-4">{titleCase(location.route_type)}</td>
                        <td className="px-4 py-4">{driver ? `${driver.name}${driver.is_active ? "" : " (Inactive)"}` : "Unclaimed"}</td>
                        <td className="px-4 py-4 text-slate-400">Not available yet</td>
                        <td className="px-4 py-4"><Link href={`/admin/locations/${location.id}`} className="font-semibold text-cyan-300 hover:text-cyan-200">Details</Link></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>
    </main>
  );
}
