"use client";

import { FormEvent, useState } from "react";
import {
  createTerminal,
  setTerminalActive,
  updateTerminal,
} from "./api";
import { requestErrorMessage } from "./errors";
import type { TerminalMapping } from "./types";

function optionalIdentifier(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

function TerminalEditor({
  locationId,
  terminal,
  onChanged,
}: {
  locationId: number;
  terminal: TerminalMapping;
  onChanged: () => Promise<void>;
}) {
  const [tpn, setTpn] = useState(terminal.tpn ?? "");
  const [termId, setTermId] = useState(terminal.term_id ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");

    try {
      await updateTerminal(locationId, terminal.id, {
        tpn: optionalIdentifier(tpn),
        term_id: optionalIdentifier(termId),
      });
      await onChanged();
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSaving(false);
    }
  }

  async function toggleStatus() {
    setSaving(true);
    setError("");

    try {
      await setTerminalActive(locationId, terminal.id, !terminal.is_active);
      await onChanged();
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={save} className="rounded-xl border border-slate-800 bg-slate-950/60 p-4">
      <div className="grid gap-4 md:grid-cols-2">
        <label className="grid gap-2 text-sm">
          TPN
          <input
            maxLength={64}
            value={tpn}
            onChange={(event) => setTpn(event.target.value)}
            placeholder="Not assigned"
            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 outline-none focus:border-cyan-400"
          />
        </label>
        <label className="grid gap-2 text-sm">
          Terminal ID
          <input
            maxLength={64}
            value={termId}
            onChange={(event) => setTermId(event.target.value)}
            placeholder="Not assigned"
            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 outline-none focus:border-cyan-400"
          />
        </label>
      </div>
      <div className="mt-4 flex flex-wrap items-center gap-3">
        <span
          className={`rounded-full px-2.5 py-1 text-xs font-semibold ${
            terminal.is_active
              ? "bg-emerald-950 text-emerald-300"
              : "bg-slate-800 text-slate-400"
          }`}
        >
          {terminal.is_active ? "Active" : "Inactive"}
        </span>
        <button
          type="submit"
          disabled={saving}
          className="rounded-lg bg-cyan-400 px-3 py-2 text-sm font-semibold text-slate-950 disabled:opacity-60"
        >
          Save mapping
        </button>
        <button
          type="button"
          disabled={saving}
          onClick={toggleStatus}
          className="rounded-lg border border-slate-700 px-3 py-2 text-sm font-semibold disabled:opacity-60"
        >
          {terminal.is_active ? "Deactivate" : "Activate"}
        </button>
      </div>
      {error && <p className="mt-3 text-sm text-red-300">{error}</p>}
    </form>
  );
}

export function TerminalManager({
  locationId,
  terminals,
  onChanged,
}: {
  locationId: number;
  terminals: TerminalMapping[];
  onChanged: () => Promise<void>;
}) {
  const [tpn, setTpn] = useState("");
  const [termId, setTermId] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  async function addTerminal(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");

    try {
      await createTerminal(locationId, {
        tpn: optionalIdentifier(tpn),
        term_id: optionalIdentifier(termId),
      });
      setTpn("");
      setTermId("");
      await onChanged();
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSaving(false);
    }
  }

  return (
    <section className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
      <div>
        <p className="text-sm font-semibold uppercase tracking-[0.16em] text-cyan-400">
          Dejavoo terminals
        </p>
        <h2 className="mt-2 text-2xl font-semibold">Terminal mappings</h2>
        <p className="mt-2 text-sm text-slate-400">
          Add real identifiers only after they are confirmed from FEED access.
        </p>
      </div>

      <form onSubmit={addTerminal} className="mt-6 rounded-xl border border-slate-700 p-4">
        <h3 className="font-semibold">Add terminal mapping</h3>
        <div className="mt-4 grid gap-4 md:grid-cols-2">
          <label className="grid gap-2 text-sm">
            TPN
            <input
              maxLength={64}
              value={tpn}
              onChange={(event) => setTpn(event.target.value)}
              placeholder="Enter confirmed TPN"
              className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 outline-none focus:border-cyan-400"
            />
          </label>
          <label className="grid gap-2 text-sm">
            Terminal ID
            <input
              maxLength={64}
              value={termId}
              onChange={(event) => setTermId(event.target.value)}
              placeholder="Enter confirmed Terminal ID"
              className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 outline-none focus:border-cyan-400"
            />
          </label>
        </div>
        <button
          type="submit"
          disabled={saving || (!tpn.trim() && !termId.trim())}
          className="mt-4 rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 disabled:opacity-60"
        >
          {saving ? "Adding…" : "Add mapping"}
        </button>
        {error && <p className="mt-3 text-sm text-red-300">{error}</p>}
      </form>

      <div className="mt-6 grid gap-4">
        {terminals.length === 0 ? (
          <p className="rounded-xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-400">
            No terminal mappings have been added.
          </p>
        ) : (
          terminals.map((terminal) => (
            <TerminalEditor
              key={terminal.id}
              locationId={locationId}
              terminal={terminal}
              onChanged={onChanged}
            />
          ))
        )}
      </div>
    </section>
  );
}
