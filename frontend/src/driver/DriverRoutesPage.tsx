"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { ProtectedRoute } from "@/auth/ProtectedRoute";
import { useAuth } from "@/auth/AuthProvider";
import { requestErrorMessage } from "@/locations/errors";
import { displayUnits, formatDateTime, titleCase } from "@/dashboards/format";
import { LoadProgress, OperationalBadge } from "@/dashboards/LoadProgress";
import type { TrailerLoad } from "@/trailer-loads/types";
import { claimDriverRoute, listDriverRoutes, swapDriverTrailer } from "./api";

export function DriverRoutesPage() {
  const { user, logout } = useAuth();
  const router = useRouter();
  const [myRoutes, setMyRoutes] = useState<TrailerLoad[]>([]);
  const [openRoutes, setOpenRoutes] = useState<TrailerLoad[]>([]);
  const [history, setHistory] = useState<TrailerLoad[]>([]);
  const [summary, setSummary] = useState({ my_active_routes: 0, open_routes: 0, ready_loads: 0 });
  const [loading, setLoading] = useState(true);
  const [claimingId, setClaimingId] = useState<number | null>(null);
  const [swappingId, setSwappingId] = useState<number | null>(null);
  const [loggingOut, setLoggingOut] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const loadRoutes = useCallback(async () => {
    try {
      const routes = await listDriverRoutes();
      setMyRoutes(routes.my_routes);
      setOpenRoutes(routes.open_routes);
      setHistory(routes.history);
      setSummary(routes.summary);
      setError("");
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;

    void listDriverRoutes()
      .then((routes) => {
        if (!cancelled) {
          setMyRoutes(routes.my_routes);
          setOpenRoutes(routes.open_routes);
          setHistory(routes.history);
          setSummary(routes.summary);
          setError("");
        }
      })
      .catch((caught) => {
        if (!cancelled) {
          setError(requestErrorMessage(caught));
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  async function claim(loadId: number) {
    setClaimingId(loadId);
    setError("");
    setSuccess("");

    try {
      await claimDriverRoute(loadId);
      await loadRoutes();
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setClaimingId(null);
    }
  }

  async function swap(load: TrailerLoad) {
    const confirmed = window.confirm(
      `Mark the trailer at ${load.location?.name ?? "this location"} as swapped? This closes the current load and immediately starts a new trailer cycle.`,
    );

    if (!confirmed) {
      return;
    }

    setSwappingId(load.id);
    setError("");
    setSuccess("");

    try {
      await swapDriverTrailer(load.id);
      await loadRoutes();
      setSuccess("Trailer swap recorded. A new active load has been created for the location.");
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSwappingId(null);
    }
  }

  async function handleLogout() {
    setLoggingOut(true);
    setError("");
    setSuccess("");

    try {
      await logout();
      router.replace("/login");
      router.refresh();
    } catch (caught) {
      setError(requestErrorMessage(caught));
      setLoggingOut(false);
    }
  }

  return (
    <ProtectedRoute allowedRole="driver">
      <main className="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-6xl">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p className="text-sm font-semibold uppercase tracking-[0.16em] text-cyan-400">
                Driver routes
              </p>
              <h1 className="mt-2 text-3xl font-semibold">Welcome, {user?.name}</h1>
            </div>
            <button
              type="button"
              disabled={loggingOut}
              onClick={handleLogout}
              className="rounded-lg border border-slate-700 px-4 py-2.5 font-semibold disabled:opacity-60"
            >
              {loggingOut ? "Signing out..." : "Sign out"}
            </button>
          </div>

          {error && (
            <p role="alert" className="mt-6 rounded-lg bg-red-950 px-4 py-3 text-red-200">
              {error}
            </p>
          )}

          {success && (
            <p role="status" className="mt-6 rounded-lg bg-emerald-950 px-4 py-3 text-emerald-200">
              {success}
            </p>
          )}

          <section className="mt-7 grid gap-4 sm:grid-cols-3">
            <SummaryCard label="My Active Routes" count={summary.my_active_routes} loading={loading} />
            <SummaryCard label="Open Routes" count={summary.open_routes} loading={loading} />
            <SummaryCard label="Ready Loads" count={summary.ready_loads} loading={loading} />
          </section>

          <RouteSection title="My Routes" loading={loading} empty="No routes are committed to you.">
            {myRoutes.map((load) => (
              <RouteCard key={load.id} load={load}>
                <button
                  type="button"
                  disabled={swappingId !== null}
                  onClick={() => swap(load)}
                  className="mt-4 rounded-lg border border-amber-700 px-4 py-2.5 font-semibold text-amber-200 disabled:opacity-60"
                >
                  {swappingId === load.id ? "Recording swap..." : "Mark Trailer Swapped"}
                </button>
              </RouteCard>
            ))}
          </RouteSection>

          <RouteSection title="Open Routes" loading={loading} empty="No open routes are available.">
            {openRoutes.map((load) => (
              <RouteCard key={load.id} load={load}>
                <button
                  type="button"
                  disabled={claimingId !== null}
                  onClick={() => claim(load.id)}
                  className="mt-4 rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 disabled:opacity-60"
                >
                  {claimingId === load.id ? "Claiming..." : "Claim Route"}
                </button>
              </RouteCard>
            ))}
          </RouteSection>

          <section className="mt-8">
            <h2 className="text-2xl font-semibold">My Haul History</h2>
            {loading ? (
              <p className="mt-4 text-slate-400">Loading haul history...</p>
            ) : history.length === 0 ? (
              <p className="mt-4 rounded-xl border border-dashed border-slate-700 p-6 text-slate-400">No completed trailer swaps yet.</p>
            ) : (
              <div className="mt-4 overflow-x-auto rounded-2xl border border-slate-800 bg-slate-900">
                <table className="w-full min-w-[720px] text-left text-sm">
                  <thead className="bg-slate-950/60 text-slate-400"><tr>{["Location", "Swapped", "Operational units", "Warehouse status", "Actual count"].map((heading) => <th key={heading} className="px-4 py-3 font-medium">{heading}</th>)}</tr></thead>
                  <tbody className="divide-y divide-slate-800">{history.map((load) => <tr key={load.id}><td className="px-4 py-4 font-semibold">{load.location?.name}</td><td className="px-4 py-4">{formatDateTime(load.swapped_at)}</td><td className="px-4 py-4">{displayUnits(load.operational_units)}</td><td className="px-4 py-4">{titleCase(load.status)}</td><td className="px-4 py-4">{load.warehouse_actual_count ?? "Pending"}</td></tr>)}</tbody>
                </table>
              </div>
            )}
          </section>
        </div>
      </main>
    </ProtectedRoute>
  );
}

function RouteSection({
  title,
  loading,
  empty,
  children,
}: {
  title: string;
  loading: boolean;
  empty: string;
  children: React.ReactNode;
}) {
  const hasChildren = Array.isArray(children) ? children.length > 0 : Boolean(children);

  return (
    <section className="mt-8">
      <h2 className="text-2xl font-semibold">{title}</h2>
      {loading ? (
        <p className="mt-4 text-slate-400">Loading routes...</p>
      ) : !hasChildren ? (
        <p className="mt-4 rounded-xl border border-dashed border-slate-700 p-6 text-slate-400">
          {empty}
        </p>
      ) : (
        <div className="mt-4 grid gap-4 md:grid-cols-2">{children}</div>
      )}
    </section>
  );
}

function RouteCard({
  load,
  children,
}: {
  load: TrailerLoad;
  children?: React.ReactNode;
}) {
  return (
    <article className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
      <h3 className="text-xl font-semibold">{load.location?.name}</h3>
      <div className="mt-3 flex flex-wrap items-center gap-4">
        <OperationalBadge status={load.operational_status} />
        <LoadProgress percentage={load.progress_percentage} />
      </div>
      <dl className="mt-4 grid grid-cols-2 gap-4 text-sm">
        <RouteDetail label="Route type" value={load.location?.route_type === "dedicated" ? "Dedicated" : "Open"} />
        <RouteDetail label="Load status" value={titleCase(load.operational_status)} />
        <RouteDetail label="Started" value={formatDateTime(load.started_at)} />
        <RouteDetail label="Operational units" value={displayUnits(load.operational_units)} />
        <RouteDetail label="Calculated units" value={displayUnits(load.calculated_units)} />
        <RouteDetail label="Threshold" value={load.location?.haul_threshold ?? "Not available"} />
        <RouteDetail label="Committed driver" value={load.committed_driver?.name ?? "Unclaimed"} />
      </dl>
      {children}
    </article>
  );
}

function SummaryCard({ label, count, loading }: { label: string; count: number; loading: boolean }) {
  return <article className="rounded-2xl border border-slate-800 bg-slate-900 p-5"><p className="text-sm text-slate-400">{label}</p><p className="mt-2 text-3xl font-semibold">{loading ? "—" : count}</p></article>;
}

function RouteDetail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-slate-500">{label}</dt>
      <dd className="mt-1 font-medium">{value}</dd>
    </div>
  );
}
