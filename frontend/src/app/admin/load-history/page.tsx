"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { getAdminLoadHistory } from "@/dashboards/api";
import { displayUnits, formatDateTime, titleCase } from "@/dashboards/format";
import type { LoadHistoryData } from "@/dashboards/types";
import { requestErrorMessage } from "@/locations/errors";
import type { TrailerLoadStatus } from "@/trailer-loads/types";

const empty: LoadHistoryData = { loads: [], locations: [] };

export default function AdminLoadHistoryPage() {
  const [data, setData] = useState(empty);
  const [locationId, setLocationId] = useState("");
  const [status, setStatus] = useState<Exclude<TrailerLoadStatus, "active"> | "">("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  useEffect(() => { let cancelled = false; void getAdminLoadHistory({ locationId, status }).then((result) => { if (!cancelled) { setData(result); setError(""); } }).catch((caught) => { if (!cancelled) setError(requestErrorMessage(caught)); }).finally(() => { if (!cancelled) setLoading(false); }); return () => { cancelled = true; }; }, [locationId, status]);

  return (
    <main className="min-h-screen px-4 py-8 sm:px-6 lg:px-8"><div className="mx-auto max-w-7xl">
      <h1 className="text-3xl font-semibold">Load History</h1><p className="mt-2 text-slate-400">Closed loads across all locations. Variance is actual count minus operational units.</p>
      <div className="mt-6 flex flex-wrap gap-3"><select value={locationId} onChange={(event) => setLocationId(event.target.value)} className="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2"><option value="">All locations</option>{data.locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select><select value={status} onChange={(event) => setStatus(event.target.value as typeof status)} className="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2"><option value="">All historical statuses</option><option value="pending_warehouse_count">Pending warehouse count</option><option value="completed">Completed</option></select></div>
      {error && <p role="alert" className="mt-5 rounded-lg bg-red-950 px-4 py-3 text-red-200">{error}</p>}
      <section className="mt-6 overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">{loading ? <p className="p-8 text-center text-slate-400">Loading history...</p> : data.loads.length === 0 ? <p className="p-8 text-center text-slate-400">No historical loads match these filters.</p> : <div className="overflow-x-auto"><table className="w-full min-w-[1200px] text-left text-sm"><thead className="bg-slate-950/60 text-slate-400"><tr>{["Location", "Status", "Started", "Swapped", "Driver", "Calculated", "Adjustments", "Operational", "Actual", "Variance", ""].map((heading) => <th key={heading} className="px-4 py-3 font-medium">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-800">{data.loads.map((load) => <tr key={load.id}><td className="px-4 py-4 font-semibold">{load.location?.name}</td><td className="px-4 py-4">{titleCase(load.status)}</td><td className="px-4 py-4">{formatDateTime(load.started_at)}</td><td className="px-4 py-4">{formatDateTime(load.swapped_at)}</td><td className="px-4 py-4">{load.swapped_by?.name ?? load.committed_driver?.name ?? "Not recorded"}</td><td className="px-4 py-4">{displayUnits(load.calculated_units)}</td><td className="px-4 py-4">{displayUnits(load.manual_adjustment_units)}</td><td className="px-4 py-4">{displayUnits(load.operational_units)}</td><td className="px-4 py-4">{load.warehouse_actual_count ?? "Pending"}</td><td className="px-4 py-4">{load.warehouse_variance === null ? "—" : displayUnits(load.warehouse_variance)}</td><td className="px-4 py-4"><Link href={`/admin/locations/${load.location_id}`} className="font-semibold text-cyan-300">Details</Link></td></tr>)}</tbody></table></div>}</section>
    </div></main>
  );
}
