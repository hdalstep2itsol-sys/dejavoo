"use client";

import { useEffect, useState } from "react";
import { requestErrorMessage } from "@/locations/errors";
import { listLoadTransactions } from "./api";
import type { NormalizedTransaction } from "./types";

function formatDateTime(value: string): string {
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}

function displayDecimal(value: string): string {
  return value.includes(".") ? value.replace(/0+$/, "").replace(/\.$/, "") : value;
}

export function LoadTransactions({
  locationId,
  loadId,
  onClose,
}: {
  locationId: number;
  loadId: number;
  onClose: () => void;
}) {
  const [transactions, setTransactions] = useState<NormalizedTransaction[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;

    void listLoadTransactions(locationId, loadId)
      .then((data) => {
        if (!cancelled) setTransactions(data);
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

  return (
    <section className="mt-6 rounded-xl border border-slate-700 p-4">
      <div className="flex items-center justify-between gap-4">
        <h3 className="font-semibold">Normalized transactions — Load #{loadId}</h3>
        <button type="button" onClick={onClose} className="text-sm font-semibold text-cyan-300">
          Close
        </button>
      </div>

      {error ? (
        <p role="alert" className="mt-4 rounded-lg bg-red-950 px-4 py-3 text-red-200">{error}</p>
      ) : loading ? (
        <p className="mt-4 text-sm text-slate-400">Loading transactions...</p>
      ) : transactions.length === 0 ? (
        <p className="mt-4 text-sm text-slate-400">No normalized transactions for this load.</p>
      ) : (
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead className="text-slate-400">
              <tr>
                <th className="border-b border-slate-800 px-3 py-2 font-medium">Type</th>
                <th className="border-b border-slate-800 px-3 py-2 font-medium">Business amount</th>
                <th className="border-b border-slate-800 px-3 py-2 font-medium">Unit price snapshot</th>
                <th className="border-b border-slate-800 px-3 py-2 font-medium">Unit delta</th>
                <th className="border-b border-slate-800 px-3 py-2 font-medium">Occurred</th>
                <th className="border-b border-slate-800 px-3 py-2 font-medium">Source</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800">
              {transactions.map((transaction) => (
                <tr key={transaction.id}>
                  <td className="px-3 py-3 capitalize">{transaction.transaction_type}</td>
                  <td className="px-3 py-3">${transaction.business_amount}</td>
                  <td className="px-3 py-3">${transaction.unit_price_snapshot}</td>
                  <td className="px-3 py-3 font-mono">{displayDecimal(transaction.unit_delta)}</td>
                  <td className="px-3 py-3">{formatDateTime(transaction.occurred_at)}</td>
                  <td className="px-3 py-3">{transaction.source}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
