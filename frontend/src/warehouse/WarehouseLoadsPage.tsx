"use client";

import { useCallback, useEffect, useState } from "react";
import type { FormEvent } from "react";
import { useRouter } from "next/navigation";
import { ProtectedRoute } from "@/auth/ProtectedRoute";
import { useAuth } from "@/auth/AuthProvider";
import { requestErrorMessage } from "@/locations/errors";
import type { TrailerLoad } from "@/trailer-loads/types";
import { confirmWarehouseLoad, listPendingWarehouseLoads } from "./api";

function formatDateTime(value: string | null): string {
  if (!value) {
    return "Not recorded";
  }

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}

function displayUnits(value: string): string {
  return value.includes(".") ? value.replace(/0+$/, "").replace(/\.$/, "") : value;
}

export function WarehouseLoadsPage() {
  const { user, logout } = useAuth();
  const router = useRouter();
  const [loads, setLoads] = useState<TrailerLoad[]>([]);
  const [selectedLoadId, setSelectedLoadId] = useState<number | null>(null);
  const [actualCount, setActualCount] = useState("");
  const [notes, setNotes] = useState("");
  const [loading, setLoading] = useState(true);
  const [confirming, setConfirming] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const loadPending = useCallback(async () => {
    try {
      setLoads(await listPendingWarehouseLoads());
      setError("");
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;

    void listPendingWarehouseLoads()
      .then((pendingLoads) => {
        if (!cancelled) {
          setLoads(pendingLoads);
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

  function openConfirmation(loadId: number) {
    setSelectedLoadId(loadId);
    setActualCount("");
    setNotes("");
    setError("");
    setSuccess("");
  }

  async function confirm(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (selectedLoadId === null || actualCount === "") {
      return;
    }

    setConfirming(true);
    setError("");
    setSuccess("");

    try {
      await confirmWarehouseLoad(selectedLoadId, Number(actualCount), notes);
      setSelectedLoadId(null);
      setActualCount("");
      setNotes("");
      await loadPending();
      setSuccess("Warehouse count confirmed. The load is now completed.");
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setConfirming(false);
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
    <ProtectedRoute allowedRole="warehouse_staff">
      <main className="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-5xl">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p className="text-sm font-semibold uppercase tracking-[0.16em] text-cyan-400">
                Warehouse workflow
              </p>
              <h1 className="mt-2 text-3xl font-semibold">Awaiting Warehouse Count</h1>
              <p className="mt-2 text-slate-400">Signed in as {user?.name}</p>
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

          <section className="mt-8 grid gap-4">
            {loading ? (
              <p className="text-slate-400">Loading pending loads...</p>
            ) : loads.length === 0 ? (
              <p className="rounded-xl border border-dashed border-slate-700 p-8 text-center text-slate-400">
                No loads are awaiting warehouse count.
              </p>
            ) : (
              loads.map((load) => (
                <article key={load.id} className="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                  <h2 className="text-xl font-semibold">{load.location?.name}</h2>
                  <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <LoadDetail
                      label="Driver who swapped"
                      value={load.swapped_by
                        ? `${load.swapped_by.name}${load.swapped_by.is_active ? "" : " (Inactive)"}`
                        : "Not recorded"}
                    />
                    <LoadDetail label="Swapped" value={formatDateTime(load.swapped_at)} />
                    <LoadDetail label="Load started" value={formatDateTime(load.started_at)} />
                    <LoadDetail label="Status" value="Pending warehouse count" />
                    <LoadDetail label="Calculated units" value={displayUnits(load.calculated_units)} />
                  </dl>

                  {selectedLoadId === load.id ? (
                    <form onSubmit={confirm} className="mt-5 max-w-xl rounded-xl border border-slate-700 p-4">
                      <label className="grid gap-2 text-sm font-medium">
                        Actual unloaded mattress count
                        <input
                          required
                          type="number"
                          min="0"
                          step="1"
                          value={actualCount}
                          onChange={(event) => setActualCount(event.target.value)}
                          className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
                        />
                      </label>
                      <label className="mt-4 grid gap-2 text-sm font-medium">
                        Warehouse notes (optional)
                        <textarea
                          maxLength={5000}
                          rows={4}
                          value={notes}
                          onChange={(event) => setNotes(event.target.value)}
                          className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
                        />
                      </label>
                      <div className="mt-4 flex flex-wrap gap-3">
                        <button
                          type="submit"
                          disabled={confirming || actualCount === ""}
                          className="rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 disabled:opacity-60"
                        >
                          {confirming ? "Confirming..." : "Confirm warehouse count"}
                        </button>
                        <button
                          type="button"
                          disabled={confirming}
                          onClick={() => setSelectedLoadId(null)}
                          className="rounded-lg border border-slate-700 px-4 py-2.5 font-semibold disabled:opacity-60"
                        >
                          Cancel
                        </button>
                      </div>
                    </form>
                  ) : (
                    <button
                      type="button"
                      onClick={() => openConfirmation(load.id)}
                      className="mt-5 rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950"
                    >
                      Enter actual count
                    </button>
                  )}
                </article>
              ))
            )}
          </section>
        </div>
      </main>
    </ProtectedRoute>
  );
}

function LoadDetail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-slate-500">{label}</dt>
      <dd className="mt-1 font-medium">{value}</dd>
    </div>
  );
}
