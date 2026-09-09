"use client";

import { useEffect, useState } from "react";
import { requestErrorMessage } from "@/locations/errors";
import { listLocationPriceHistory } from "./api";
import type { LocationPriceHistory } from "./types";

function formatDateTime(value: string): string {
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}

export function LocationPriceHistoryPanel({ locationId }: { locationId: number }) {
  const [history, setHistory] = useState<LocationPriceHistory[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;

    void listLocationPriceHistory(locationId)
      .then((data) => {
        if (!cancelled) setHistory(data);
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
  }, [locationId]);

  return (
    <section className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
      <p className="text-sm font-semibold uppercase tracking-[0.16em] text-cyan-400">
        Unit price history
      </p>
      <p className="mt-2 text-sm text-slate-400">
        Price changes are effective immediately and historical entries cannot be edited.
      </p>

      {error ? (
        <p role="alert" className="mt-4 rounded-lg bg-red-950 px-4 py-3 text-red-200">{error}</p>
      ) : loading ? (
        <p className="mt-4 text-sm text-slate-400">Loading price history...</p>
      ) : (
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[560px] text-left text-sm">
            <thead className="text-slate-400">
              <tr>
                <th className="border-b border-slate-800 px-3 py-2 font-medium">Unit price</th>
                <th className="border-b border-slate-800 px-3 py-2 font-medium">Effective from</th>
                <th className="border-b border-slate-800 px-3 py-2 font-medium">Status</th>
                <th className="border-b border-slate-800 px-3 py-2 font-medium">Changed by</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800">
              {history.map((price, index) => (
                <tr key={price.id}>
                  <td className="px-3 py-3">${price.unit_price}</td>
                  <td className="px-3 py-3">{formatDateTime(price.effective_from)}</td>
                  <td className="px-3 py-3">
                    <span className={index === 0 ? "text-emerald-300" : "text-slate-400"}>
                      {index === 0 ? "Current" : "Previous"}
                    </span>
                  </td>
                  <td className="px-3 py-3">{price.created_by?.name ?? "System backfill"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
