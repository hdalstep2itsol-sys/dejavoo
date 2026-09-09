"use client";

import type { FormEvent } from "react";
import { useEffect, useState } from "react";
import { displayUnits, formatDateTime, titleCase } from "@/dashboards/format";
import { requestErrorMessage } from "@/locations/errors";
import { downloadTrailerLoadReport, getTrailerLoadReport } from "@/reports/api";
import type { ReportFilters, ReportResponse } from "@/reports/types";

const emptyFilters: ReportFilters = {
  locationId: "",
  status: "",
  startDate: "",
  endDate: "",
  driverId: "",
};

const emptyReport: ReportResponse = {
  data: [],
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
  filter_options: { locations: [], drivers: [] },
};

export default function ReportsPage() {
  const [draft, setDraft] = useState<ReportFilters>(emptyFilters);
  const [filters, setFilters] = useState<ReportFilters>(emptyFilters);
  const [page, setPage] = useState(1);
  const [report, setReport] = useState<ReportResponse>(emptyReport);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState<"csv" | "xlsx" | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;
    void getTrailerLoadReport(filters, page)
      .then((result) => {
        if (!cancelled) {
          setReport(result);
          setError("");
        }
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
  }, [filters, page]);

  function applyFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setPage(1);
    setFilters({ ...draft });
  }

  function resetFilters() {
    setLoading(true);
    setDraft(emptyFilters);
    setFilters(emptyFilters);
    setPage(1);
  }

  async function exportReport(format: "csv" | "xlsx") {
    setExporting(format);
    setError("");
    try {
      await downloadTrailerLoadReport(format, filters);
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setExporting(null);
    }
  }

  return (
    <main className="min-h-screen px-4 py-8 sm:px-6 lg:px-8">
      <div className="mx-auto max-w-[1500px]">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 className="text-3xl font-semibold">Trailer / Load Report</h1>
            <p className="mt-2 text-slate-400">One row per load cycle, using current lifecycle data.</p>
          </div>
          <div className="flex gap-3">
            <button type="button" onClick={() => exportReport("csv")} disabled={exporting !== null} className="rounded-lg border border-cyan-700 px-4 py-2.5 font-semibold text-cyan-300 disabled:opacity-60">{exporting === "csv" ? "Exporting..." : "CSV Export"}</button>
            <button type="button" onClick={() => exportReport("xlsx")} disabled={exporting !== null} className="rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 disabled:opacity-60">{exporting === "xlsx" ? "Exporting..." : "XLSX Export"}</button>
          </div>
        </div>

        <form onSubmit={applyFilters} className="mt-7 grid gap-4 rounded-2xl border border-slate-800 bg-slate-900 p-5 sm:grid-cols-2 xl:grid-cols-5">
          <Filter label="Location"><select value={draft.locationId} onChange={(event) => setDraft((value) => ({ ...value, locationId: event.target.value }))} className="field"><option value="">All locations</option>{report.filter_options.locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></Filter>
          <Filter label="Load status"><select value={draft.status} onChange={(event) => setDraft((value) => ({ ...value, status: event.target.value as ReportFilters["status"] }))} className="field"><option value="">All statuses</option><option value="active">Active</option><option value="pending_warehouse_count">Pending warehouse count</option><option value="completed">Completed</option></select></Filter>
          <Filter label="Start date"><input type="date" value={draft.startDate} onChange={(event) => setDraft((value) => ({ ...value, startDate: event.target.value }))} className="field" /></Filter>
          <Filter label="End date"><input type="date" value={draft.endDate} min={draft.startDate || undefined} onChange={(event) => setDraft((value) => ({ ...value, endDate: event.target.value }))} className="field" /></Filter>
          <Filter label="Driver"><select value={draft.driverId} onChange={(event) => setDraft((value) => ({ ...value, driverId: event.target.value }))} className="field"><option value="">All drivers</option>{report.filter_options.drivers.map((driver) => <option key={driver.id} value={driver.id}>{driver.name}{driver.is_active ? "" : " (Inactive)"}</option>)}</select></Filter>
          <div className="flex flex-wrap gap-3 sm:col-span-2 xl:col-span-5">
            <button type="submit" disabled={loading} className="rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 disabled:opacity-60">Apply filters</button>
            <button type="button" onClick={resetFilters} disabled={loading} className="rounded-lg border border-slate-700 px-4 py-2.5 font-semibold disabled:opacity-60">Reset</button>
          </div>
        </form>

        {error && <p role="alert" className="mt-5 rounded-lg bg-red-950 px-4 py-3 text-red-200">{error}</p>}

        <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-slate-400"><span className="font-semibold text-white">{report.meta.total}</span> result{report.meta.total === 1 ? "" : "s"}</p>
          <p className="text-sm text-slate-500">Showing {report.meta.from ?? 0}–{report.meta.to ?? 0}</p>
        </div>

        <section className="mt-3 overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">
          {loading ? <p className="p-8 text-center text-slate-400">Loading report...</p> : report.data.length === 0 ? <p className="p-8 text-center text-slate-400">No load cycles match the selected filters.</p> : (
            <div className="overflow-x-auto"><table className="w-full min-w-[1500px] text-left text-sm"><thead className="sticky top-0 bg-slate-950 text-slate-400"><tr>{["Location", "Status", "Started", "Swapped", "Driver", "Route", "Calculated", "Adjustments", "Operational", "Actual", "Variance", "Confirmed"].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-medium">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-800">{report.data.map((row) => <tr key={row.id}><td className="px-4 py-4 font-semibold text-white">{row.location.name}</td><td className="px-4 py-4">{titleCase(row.status)}</td><td className="whitespace-nowrap px-4 py-4">{formatDateTime(row.started_at)}</td><td className="whitespace-nowrap px-4 py-4">{formatDateTime(row.swapped_at)}</td><td className="px-4 py-4">{row.driver ? `${row.driver.name}${row.driver.is_active ? "" : " (Inactive)"}` : "Not assigned"}</td><td className="px-4 py-4">{titleCase(row.route_type)}</td><td className="px-4 py-4 tabular-nums">{displayUnits(row.calculated_units)}</td><td className="px-4 py-4 tabular-nums">{displayUnits(row.manual_adjustment_units)}</td><td className="px-4 py-4 tabular-nums">{displayUnits(row.operational_units)}</td><td className="px-4 py-4 tabular-nums">{row.warehouse_actual_count ?? "—"}</td><td className="px-4 py-4 tabular-nums">{row.warehouse_variance === null ? "—" : displayUnits(row.warehouse_variance)}</td><td className="whitespace-nowrap px-4 py-4">{formatDateTime(row.warehouse_confirmed_at)}</td></tr>)}</tbody></table></div>
          )}
        </section>

        {report.meta.last_page > 1 && <nav className="mt-5 flex items-center justify-end gap-3" aria-label="Report pagination"><button type="button" disabled={loading || page <= 1} onClick={() => { setLoading(true); setPage((value) => value - 1); }} className="rounded-lg border border-slate-700 px-4 py-2 disabled:opacity-50">Previous</button><span className="text-sm text-slate-400">Page {report.meta.current_page} of {report.meta.last_page}</span><button type="button" disabled={loading || page >= report.meta.last_page} onClick={() => { setLoading(true); setPage((value) => value + 1); }} className="rounded-lg border border-slate-700 px-4 py-2 disabled:opacity-50">Next</button></nav>}
      </div>
      <style jsx>{`.field { width: 100%; border-radius: 0.5rem; border: 1px solid rgb(51 65 85); background: rgb(2 6 23); padding: 0.625rem 0.75rem; outline: none; } .field:focus { border-color: rgb(34 211 238); }`}</style>
    </main>
  );
}

function Filter({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className="grid gap-2 text-sm font-medium"><span className="text-slate-300">{label}</span>{children}</label>;
}
