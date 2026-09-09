"use client";

import { useCallback, useEffect, useState } from "react";
import type { FormEvent } from "react";
import type { Location } from "@/locations/types";
import {
  assignTrailerLoadDriver,
  getCurrentTrailerLoad,
  initializeTrailerLoad,
  listTrailerLoads,
  removeTrailerLoadDriver,
} from "./api";
import type { TrailerLoad, TrailerLoadStatus } from "./types";
import { requestErrorMessage } from "@/locations/errors";

const statusLabels: Record<TrailerLoadStatus, string> = {
  active: "Active",
  pending_warehouse_count: "Pending warehouse count",
  completed: "Completed",
};

function formatDateTime(value: string): string {
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}

import type { DriverOption } from "@/locations/types";

const commitmentLabels = {
  dedicated: "Dedicated",
  open_claim: "Open claim",
};

export function TrailerLoadPanel({
  location,
  drivers,
}: {
  location: Location;
  drivers: DriverOption[];
}) {
  const [currentLoad, setCurrentLoad] = useState<TrailerLoad | null>(null);
  const [history, setHistory] = useState<TrailerLoad[]>([]);
  const [startedAt, setStartedAt] = useState("");
  const [selectedDriverId, setSelectedDriverId] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    try {
      const [current, loads] = await Promise.all([
        getCurrentTrailerLoad(location.id),
        listTrailerLoads(location.id),
      ]);
      setCurrentLoad(current);
      setHistory(loads);
      setSelectedDriverId(current?.committed_driver?.id.toString() ?? "");
      setError("");
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setLoading(false);
    }
  }, [location.id]);

  useEffect(() => {
    let cancelled = false;

    void Promise.all([
      getCurrentTrailerLoad(location.id),
      listTrailerLoads(location.id),
    ])
      .then(([current, loads]) => {
        if (!cancelled) {
          setCurrentLoad(current);
          setHistory(loads);
          setSelectedDriverId(current?.committed_driver?.id.toString() ?? "");
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
  }, [location.id]);

  async function initialize(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");

    try {
      await initializeTrailerLoad(location.id, new Date(startedAt).toISOString());
      setStartedAt("");
      await load();
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSaving(false);
    }
  }

  async function assignDriver(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!currentLoad || !selectedDriverId) {
      return;
    }

    setSaving(true);
    setError("");

    try {
      const updated = await assignTrailerLoadDriver(
        location.id,
        currentLoad.id,
        Number(selectedDriverId),
      );
      setCurrentLoad(updated);
      setSelectedDriverId(updated.committed_driver?.id.toString() ?? "");
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSaving(false);
    }
  }

  async function removeDriver() {
    if (!currentLoad) {
      return;
    }

    setSaving(true);
    setError("");

    try {
      const updated = await removeTrailerLoadDriver(location.id, currentLoad.id);
      setCurrentLoad(updated);
      setSelectedDriverId("");
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSaving(false);
    }
  }

  return (
    <section className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
      <p className="text-sm font-semibold uppercase tracking-[0.16em] text-cyan-400">
        Current trailer / load
      </p>

      {loading ? (
        <p className="mt-4 text-slate-400">Loading load cycles...</p>
      ) : currentLoad ? (
        <dl className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <LoadDetail label="Status" value={statusLabels[currentLoad.status]} />
          <LoadDetail label="Started" value={formatDateTime(currentLoad.started_at)} />
          <LoadDetail label="Location" value={location.name} />
          <LoadDetail label="Current units" value="Not available yet" />
          <LoadDetail label="Route type" value={location.route_type === "open" ? "Open" : "Dedicated"} />
          <LoadDetail
            label="Dedicated driver"
            value={location.dedicated_driver?.name ?? "Not applicable"}
          />
          <LoadDetail
            label="Committed driver"
            value={currentLoad.committed_driver?.name ?? "Not committed"}
          />
          <LoadDetail
            label="Commitment source"
            value={
              currentLoad.commitment_source
                ? commitmentLabels[currentLoad.commitment_source]
                : "Not committed"
            }
          />
          <LoadDetail
            label="Committed at"
            value={
              currentLoad.committed_at
                ? formatDateTime(currentLoad.committed_at)
                : "Not committed"
            }
          />
        </dl>
      ) : (
        <div className="mt-5">
          <p className="rounded-lg border border-dashed border-slate-700 p-4 text-slate-300">
            No active trailer/load has been initialized.
          </p>

          <form onSubmit={initialize} className="mt-5 max-w-md rounded-xl border border-slate-700 p-4">
            <h2 className="font-semibold">Initialize Active Trailer</h2>
            <p className="mt-1 text-sm text-slate-400">
              Enter the actual trailer start or reset date and time.
            </p>
            <label className="mt-4 grid gap-2 text-sm font-medium">
              Trailer start/reset date and time
              <input
                required
                type="datetime-local"
                value={startedAt}
                onChange={(event) => setStartedAt(event.target.value)}
                className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
              />
            </label>
            <button
              type="submit"
              disabled={saving || !startedAt}
              className="mt-4 rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 disabled:opacity-60"
            >
              {saving ? "Initializing..." : "Initialize active trailer"}
            </button>
          </form>
        </div>
      )}

      {!loading && currentLoad && (
        <form
          onSubmit={assignDriver}
          className="mt-6 max-w-xl rounded-xl border border-slate-700 p-4"
        >
          <h2 className="font-semibold">Driver commitment</h2>
          <p className="mt-1 text-sm text-slate-400">
            Assign, change, or remove the driver committed to this active load.
          </p>
          <label className="mt-4 grid gap-2 text-sm font-medium">
            Committed driver
            <select
              required
              value={selectedDriverId}
              onChange={(event) => setSelectedDriverId(event.target.value)}
              className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
            >
              <option value="">Select a driver</option>
              {drivers.map((driver) => (
                <option key={driver.id} value={driver.id}>
                  {driver.name} ({driver.email})
                </option>
              ))}
            </select>
          </label>
          <div className="mt-4 flex flex-wrap gap-3">
            <button
              type="submit"
              disabled={saving || !selectedDriverId}
              className="rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 disabled:opacity-60"
            >
              {saving ? "Saving..." : "Assign / change driver"}
            </button>
            {currentLoad.committed_driver && (
              <button
                type="button"
                disabled={saving}
                onClick={removeDriver}
                className="rounded-lg border border-red-900 px-4 py-2.5 font-semibold text-red-300 disabled:opacity-60"
              >
                Remove commitment
              </button>
            )}
          </div>
        </form>
      )}

      {error && (
        <p role="alert" className="mt-4 rounded-lg bg-red-950 px-4 py-3 text-red-200">
          {error}
        </p>
      )}

      <div className="mt-8 border-t border-slate-800 pt-6">
        <h2 className="text-xl font-semibold">Load History</h2>
        {loading ? null : history.length === 0 ? (
          <p className="mt-3 text-sm text-slate-400">No load cycles have been recorded.</p>
        ) : (
          <div className="mt-4 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="text-slate-400">
                <tr>
                  <th className="border-b border-slate-800 px-3 py-2 font-medium">Status</th>
                  <th className="border-b border-slate-800 px-3 py-2 font-medium">Started</th>
                  <th className="border-b border-slate-800 px-3 py-2 font-medium">Swapped</th>
                  <th className="border-b border-slate-800 px-3 py-2 font-medium">Swapped by</th>
                  <th className="border-b border-slate-800 px-3 py-2 font-medium">Actual count</th>
                  <th className="border-b border-slate-800 px-3 py-2 font-medium">Confirmed</th>
                  <th className="border-b border-slate-800 px-3 py-2 font-medium">Confirmed by</th>
                  <th className="border-b border-slate-800 px-3 py-2 font-medium">Notes</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800">
                {history.map((loadCycle) => (
                  <tr key={loadCycle.id}>
                    <td className="px-3 py-3">{statusLabels[loadCycle.status]}</td>
                    <td className="px-3 py-3">{formatDateTime(loadCycle.started_at)}</td>
                    <td className="px-3 py-3">
                      {loadCycle.swapped_at ? formatDateTime(loadCycle.swapped_at) : "Not available"}
                    </td>
                    <td className="px-3 py-3">{loadCycle.swapped_by?.name ?? "Not available"}</td>
                    <td className="px-3 py-3">
                      {loadCycle.warehouse_actual_count ?? "Not available"}
                    </td>
                    <td className="px-3 py-3">
                      {loadCycle.warehouse_confirmed_at
                        ? formatDateTime(loadCycle.warehouse_confirmed_at)
                        : "Not available"}
                    </td>
                    <td className="px-3 py-3">
                      {loadCycle.warehouse_confirmed_by?.name ?? "Not available"}
                    </td>
                    <td className="max-w-xs whitespace-normal px-3 py-3">
                      {loadCycle.warehouse_notes ?? "Not available"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </section>
  );
}

function LoadDetail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-sm text-slate-500">{label}</dt>
      <dd className="mt-1 font-medium text-slate-100">{value}</dd>
    </div>
  );
}
