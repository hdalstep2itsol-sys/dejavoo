"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { getAdminPendingLoads } from "@/dashboards/api";
import { displayUnits, formatDateTime } from "@/dashboards/format";
import { requestErrorMessage } from "@/locations/errors";
import type { TrailerLoad } from "@/trailer-loads/types";

export default function AdminWarehousePendingPage() {
  const [loads, setLoads] = useState<TrailerLoad[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  useEffect(() => { let cancelled = false; void getAdminPendingLoads().then((data) => { if (!cancelled) setLoads(data); }).catch((caught) => { if (!cancelled) setError(requestErrorMessage(caught)); }).finally(() => { if (!cancelled) setLoading(false); }); return () => { cancelled = true; }; }, []);

  return (
    <main className="min-h-screen px-4 py-8 sm:px-6 lg:px-8"><div className="mx-auto max-w-7xl">
      <h1 className="text-3xl font-semibold">Pending Warehouse Counts</h1><p className="mt-2 text-slate-400">Read-only operational view. Warehouse staff complete counts.</p>
      {error && <p role="alert" className="mt-5 rounded-lg bg-red-950 px-4 py-3 text-red-200">{error}</p>}
      <section className="mt-6 overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">{loading ? <p className="p-8 text-center text-slate-400">Loading pending loads...</p> : loads.length === 0 ? <p className="p-8 text-center text-slate-400">No loads are awaiting warehouse count.</p> : <div className="overflow-x-auto"><table className="w-full min-w-[950px] text-left text-sm"><thead className="bg-slate-950/60 text-slate-400"><tr>{["Location", "Swapped by", "Swapped at", "Calculated", "Adjustments", "Operational", "Warehouse actual", ""].map((heading) => <th key={heading} className="px-4 py-3 font-medium">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-800">{loads.map((load) => <tr key={load.id}><td className="px-4 py-4 font-semibold">{load.location?.name}</td><td className="px-4 py-4">{load.swapped_by?.name ?? "Not recorded"}</td><td className="px-4 py-4">{formatDateTime(load.swapped_at)}</td><td className="px-4 py-4">{displayUnits(load.calculated_units)}</td><td className="px-4 py-4">{displayUnits(load.manual_adjustment_units)}</td><td className="px-4 py-4">{displayUnits(load.operational_units)}</td><td className="px-4 py-4 text-amber-300">Pending</td><td className="px-4 py-4"><Link href={`/admin/locations/${load.location_id}`} className="font-semibold text-cyan-300">Location / load detail</Link></td></tr>)}</tbody></table></div>}</section>
    </div></main>
  );
}
