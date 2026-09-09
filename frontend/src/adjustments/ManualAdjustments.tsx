"use client";

import { FormEvent, useEffect, useState } from "react";
import { requestErrorMessage } from "@/locations/errors";
import { createLoadAdjustment, listLoadAdjustments } from "./api";
import type { TrailerLoadAdjustment } from "./types";

function formatDateTime(value: string): string {
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}

function displayUnits(value: string): string {
  return value.includes(".") ? value.replace(/0+$/, "").replace(/\.$/, "") : value;
}

export function ManualAdjustments({
  locationId,
  loadId,
  onChanged,
}: {
  locationId: number;
  loadId: number;
  onChanged: () => Promise<void>;
}) {
  const [adjustments, setAdjustments] = useState<TrailerLoadAdjustment[]>([]);
  const [unitDelta, setUnitDelta] = useState("");
  const [reason, setReason] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;

    void listLoadAdjustments(locationId, loadId)
      .then((data) => {
        if (!cancelled) setAdjustments(data);
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
  }, [loadId, locationId]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");

    try {
      const created = await createLoadAdjustment(locationId, loadId, unitDelta, reason);
      setAdjustments((current) => [created, ...current]);
      setUnitDelta("");
      setReason("");
      await onChanged();
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSaving(false);
    }
  }

  return (
    <section className="mt-6 rounded-xl border border-slate-700 p-4">
      <h3 className="font-semibold">Manual adjustments — Load #{loadId}</h3>
      <p className="mt-1 text-sm text-slate-400">
        Entries are permanent. Correct mistakes with a compensating adjustment.
      </p>

      <form onSubmit={submit} className="mt-4 grid gap-4 md:grid-cols-[180px_1fr_auto] md:items-end">
        <label className="grid gap-2 text-sm font-medium">
          Unit adjustment
          <input
            required
            type="number"
            step="0.00000001"
            value={unitDelta}
            onChange={(event) => setUnitDelta(event.target.value)}
            placeholder="e.g. 1.5 or -0.25"
            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
          />
        </label>
        <label className="grid gap-2 text-sm font-medium">
          Reason
          <input
            required
            maxLength={1000}
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
          />
        </label>
        <button
          type="submit"
          disabled={saving}
          className="rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 disabled:opacity-60"
        >
          {saving ? "Adding..." : "Add adjustment"}
        </button>
      </form>

      {error && (
        <p role="alert" className="mt-4 rounded-lg bg-red-950 px-4 py-3 text-red-200">{error}</p>
      )}

      {loading ? (
        <p className="mt-4 text-sm text-slate-400">Loading adjustments...</p>
      ) : adjustments.length === 0 ? (
        <p className="mt-4 text-sm text-slate-400">No manual adjustments for this load.</p>
      ) : (
        <div className="mt-4 grid gap-3">
          {adjustments.map((adjustment) => (
            <article key={adjustment.id} className="rounded-lg bg-slate-950/70 p-3 text-sm">
              <div className="flex flex-wrap justify-between gap-2">
                <span className="font-mono font-semibold text-cyan-300">
                  {displayUnits(adjustment.unit_delta)} units
                </span>
                <span className="text-slate-500">{formatDateTime(adjustment.created_at)}</span>
              </div>
              <p className="mt-2 text-slate-200">{adjustment.reason}</p>
              <p className="mt-1 text-xs text-slate-500">Added by {adjustment.created_by.name}</p>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
