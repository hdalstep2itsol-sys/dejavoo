"use client";

import { FormEvent, useState } from "react";
import type { DriverOption, LocationInput, LocationRouteType } from "./types";

const emptyLocation: LocationInput = {
  name: "",
  unit_price: "",
  haul_threshold: "70.00",
  route_type: "open",
  dedicated_driver_id: null,
};

export function LocationForm({
  drivers,
  initialValue = emptyLocation,
  submitLabel,
  submitting,
  onSubmit,
  onCancel,
}: {
  drivers: DriverOption[];
  initialValue?: LocationInput;
  submitLabel: string;
  submitting: boolean;
  onSubmit: (input: LocationInput) => Promise<void>;
  onCancel?: () => void;
}) {
  const [form, setForm] = useState<LocationInput>(initialValue);

  function setRouteType(routeType: LocationRouteType) {
    setForm((current) => ({
      ...current,
      route_type: routeType,
      dedicated_driver_id:
        routeType === "open" ? null : current.dedicated_driver_id,
    }));
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await onSubmit(form);
  }

  return (
    <form className="grid gap-5" onSubmit={handleSubmit}>
      <label className="grid gap-2 text-sm font-medium">
        Location name
        <input
          required
          maxLength={255}
          value={form.name}
          onChange={(event) => setForm({ ...form, name: event.target.value })}
          className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
        />
      </label>

      <div className="grid gap-5 sm:grid-cols-2">
        <label className="grid gap-2 text-sm font-medium">
          Price per unit
          <input
            required
            type="number"
            min="0.01"
            step="0.01"
            value={form.unit_price}
            onChange={(event) => setForm({ ...form, unit_price: event.target.value })}
            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
          />
        </label>
        <label className="grid gap-2 text-sm font-medium">
          Haul threshold
          <input
            required
            type="number"
            min="0.01"
            step="0.01"
            value={form.haul_threshold}
            onChange={(event) => setForm({ ...form, haul_threshold: event.target.value })}
            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
          />
        </label>
      </div>

      <label className="grid gap-2 text-sm font-medium">
        Route type
        <select
          value={form.route_type}
          onChange={(event) => setRouteType(event.target.value as LocationRouteType)}
          className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
        >
          <option value="open">Open</option>
          <option value="dedicated">Dedicated</option>
        </select>
      </label>

      {form.route_type === "dedicated" && (
        <label className="grid gap-2 text-sm font-medium">
          Dedicated driver
          <select
            required
            value={form.dedicated_driver_id ?? ""}
            onChange={(event) =>
              setForm({
                ...form,
                dedicated_driver_id: event.target.value ? Number(event.target.value) : null,
              })
            }
            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
          >
            <option value="">Select a driver</option>
            {drivers.map((driver) => (
              <option key={driver.id} value={driver.id} disabled={!driver.is_active}>
                {driver.name} ({driver.email}){driver.is_active ? "" : " - Inactive"}
              </option>
            ))}
          </select>
        </label>
      )}

      <div className="flex flex-wrap gap-3">
        <button
          type="submit"
          disabled={submitting}
          className="rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60"
        >
          {submitting ? "Saving…" : submitLabel}
        </button>
        {onCancel && (
          <button
            type="button"
            onClick={onCancel}
            className="rounded-lg border border-slate-700 px-4 py-2.5 font-semibold text-slate-200 hover:bg-slate-800"
          >
            Cancel
          </button>
        )}
      </div>
    </form>
  );
}
