"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import { getAdminRoutes } from "@/dashboards/api";
import { displayUnits, formatDateTime, titleCase } from "@/dashboards/format";
import { LoadProgress, OperationalBadge } from "@/dashboards/LoadProgress";
import { listDrivers } from "@/locations/api";
import { requestErrorMessage } from "@/locations/errors";
import type { DriverOption } from "@/locations/types";
import { assignTrailerLoadDriver, removeTrailerLoadDriver } from "@/trailer-loads/api";
import type { TrailerLoad } from "@/trailer-loads/types";

export default function AdminRoutesPage() {
  const [loads, setLoads] = useState<TrailerLoad[]>([]);
  const [drivers, setDrivers] = useState<DriverOption[]>([]);
  const [selected, setSelected] = useState<Record<number, string>>({});
  const [statusFilter, setStatusFilter] = useState("");
  const [assignmentFilter, setAssignmentFilter] = useState("");
  const [loading, setLoading] = useState(true);
  const [savingId, setSavingId] = useState<number | null>(null);
  const [error, setError] = useState("");

  const loadData = useCallback(async () => {
    const [routeData, driverData] = await Promise.all([getAdminRoutes(), listDrivers()]);
    setLoads(routeData);
    setDrivers(driverData);
    setSelected(Object.fromEntries(routeData.map((load) => [load.id, load.committed_driver ? String(load.committed_driver.id) : ""])));
  }, []);

  useEffect(() => {
    void Promise.all([getAdminRoutes(), listDrivers()])
      .then(([routeData, driverData]) => {
        setLoads(routeData);
        setDrivers(driverData);
        setSelected(Object.fromEntries(routeData.map((load) => [load.id, load.committed_driver ? String(load.committed_driver.id) : ""])));
        const params = new URLSearchParams(window.location.search);
        setStatusFilter(params.get("status") ?? "");
        setAssignmentFilter(params.get("assignment") ?? "");
      })
      .catch((caught) => setError(requestErrorMessage(caught)))
      .finally(() => setLoading(false));
  }, [loadData]);

  const visibleLoads = useMemo(() => loads.filter((load) => {
    if (statusFilter && load.operational_status !== statusFilter) return false;
    if (assignmentFilter === "unclaimed" && load.committed_driver !== null) return false;
    return true;
  }), [assignmentFilter, loads, statusFilter]);

  async function saveAssignment(load: TrailerLoad) {
    setSavingId(load.id);
    setError("");
    try {
      const driverId = selected[load.id];
      if (driverId) await assignTrailerLoadDriver(load.location_id, load.id, Number(driverId));
      else await removeTrailerLoadDriver(load.location_id, load.id);
      await loadData();
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSavingId(null);
    }
  }

  return (
    <main className="min-h-screen px-4 py-8 sm:px-6 lg:px-8"><div className="mx-auto max-w-7xl">
      <h1 className="text-3xl font-semibold">Routes / Assignments</h1><p className="mt-2 text-slate-400">Current active loads and driver commitments.</p>
      <div className="mt-6 flex flex-wrap gap-3"><select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} className="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2"><option value="">All readiness statuses</option><option value="active">Active</option><option value="ready">Ready</option></select><select value={assignmentFilter} onChange={(event) => setAssignmentFilter(event.target.value)} className="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2"><option value="">All assignment states</option><option value="unclaimed">Open / unclaimed</option></select></div>
      {error && <p role="alert" className="mt-5 rounded-lg bg-red-950 px-4 py-3 text-red-200">{error}</p>}
      <section className="mt-6 grid gap-4">
        {loading ? <p className="text-slate-400">Loading routes...</p> : visibleLoads.length === 0 ? <p className="rounded-xl border border-dashed border-slate-700 p-8 text-center text-slate-400">No active routes match these filters.</p> : visibleLoads.map((load) => (
          <article key={load.id} className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-xl font-semibold">{load.location?.name}</h2><p className="mt-1 text-sm text-slate-500">Started {formatDateTime(load.started_at)}</p></div><OperationalBadge status={load.operational_status} /></div>
            <dl className="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4"><Detail label="Route type" value={titleCase(load.location?.route_type ?? "open")} /><Detail label="Operational / threshold" value={`${displayUnits(load.operational_units)} / ${displayUnits(load.location?.haul_threshold ?? "0")}`} /><div><dt className="text-slate-500">Progress</dt><dd className="mt-1"><LoadProgress percentage={load.progress_percentage} /></dd></div><Detail label="Commitment" value={load.committed_driver ? `${load.committed_driver.name}${load.committed_driver.is_active ? "" : " (Inactive)"}` : "Unclaimed"} /><Detail label="Commitment source" value={load.commitment_source ? titleCase(load.commitment_source) : "None"} /><Detail label="Assignment state" value={load.committed_driver ? "Committed" : "Available"} /></dl>
            <div className="mt-5 flex flex-wrap items-center gap-3"><select value={selected[load.id] ?? ""} onChange={(event) => setSelected((current) => ({ ...current, [load.id]: event.target.value }))} className="min-w-56 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5"><option value="">No committed driver</option>{drivers.map((driver) => <option key={driver.id} value={driver.id}>{driver.name}</option>)}</select><button type="button" disabled={savingId !== null} onClick={() => saveAssignment(load)} className="rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 disabled:opacity-60">{savingId === load.id ? "Saving..." : "Save assignment"}</button><Link href={`/admin/locations/${load.location_id}`} className="font-semibold text-cyan-300">Location detail</Link></div>
          </article>
        ))}
      </section>
    </div></main>
  );
}

function Detail({ label, value }: { label: string; value: string }) { return <div><dt className="text-slate-500">{label}</dt><dd className="mt-1 font-medium">{value}</dd></div>; }
