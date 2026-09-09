"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { ProtectedRoute } from "@/auth/ProtectedRoute";
import { createLocation, listDrivers, listLocations, setLocationActive } from "@/locations/api";
import { requestErrorMessage } from "@/locations/errors";
import { LocationForm } from "@/locations/LocationForm";
import type { DriverOption, Location, LocationInput } from "@/locations/types";

function money(value: string): string {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
  }).format(Number(value));
}

export default function LocationsPage() {
  const [locations, setLocations] = useState<Location[]>([]);
  const [drivers, setDrivers] = useState<DriverOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    try {
      const [locationData, driverData] = await Promise.all([
        listLocations(),
        listDrivers(),
      ]);
      setLocations(locationData);
      setDrivers(driverData);
      setError("");
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;

    void Promise.all([listLocations(), listDrivers()])
      .then(([locationData, driverData]) => {
        if (!cancelled) {
          setLocations(locationData);
          setDrivers(driverData);
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

  async function addLocation(input: LocationInput) {
    setSaving(true);
    setError("");
    try {
      await createLocation(input);
      setShowForm(false);
      await load();
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSaving(false);
    }
  }

  async function toggleStatus(location: Location) {
    setError("");
    try {
      await setLocationActive(location.id, !location.is_active);
      await load();
    } catch (caught) {
      setError(requestErrorMessage(caught));
    }
  }

  return (
    <ProtectedRoute allowedRole="owner_admin">
      <main className="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-7xl">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <Link href="/admin" className="text-sm text-cyan-400 hover:text-cyan-300">
                ← Owner/Admin
              </Link>
              <h1 className="mt-3 text-3xl font-semibold">Locations</h1>
              <p className="mt-2 text-slate-400">
                Configure drop-off pricing, haul thresholds, routes, and terminal mappings.
              </p>
            </div>
            <button
              type="button"
              onClick={() => setShowForm((current) => !current)}
              className="rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 hover:bg-cyan-300"
            >
              {showForm ? "Close form" : "Add location"}
            </button>
          </div>

          {error && (
            <p role="alert" className="mt-6 rounded-lg bg-red-950 px-4 py-3 text-red-200">
              {error}
            </p>
          )}

          {showForm && (
            <section className="mt-6 rounded-2xl border border-slate-800 bg-slate-900 p-6">
              <h2 className="mb-5 text-xl font-semibold">New location</h2>
              <LocationForm
                drivers={drivers}
                submitLabel="Create location"
                submitting={saving}
                onSubmit={addLocation}
                onCancel={() => setShowForm(false)}
              />
            </section>
          )}

          <section className="mt-8 overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">
            {loading ? (
              <p className="p-8 text-center text-slate-400">Loading locations…</p>
            ) : locations.length === 0 ? (
              <p className="p-8 text-center text-slate-400">No locations have been added.</p>
            ) : (
              <>
                <div className="grid gap-4 p-4 md:hidden">
                  {locations.map((location) => (
                    <article key={location.id} className="rounded-xl border border-slate-700 p-4">
                      <div className="flex items-start justify-between gap-3">
                        <Link href={`/admin/locations/${location.id}`} className="font-semibold text-cyan-300">
                          {location.name}
                        </Link>
                        <Status active={location.is_active} />
                      </div>
                      <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div><dt className="text-slate-500">Unit price</dt><dd>{money(location.unit_price)}</dd></div>
                        <div><dt className="text-slate-500">Threshold</dt><dd>{location.haul_threshold}</dd></div>
                        <div><dt className="text-slate-500">Route</dt><dd className="capitalize">{location.route_type}</dd></div>
                        <div>
                          <dt className="text-slate-500">Dedicated driver</dt>
                          <dd>
                            {location.dedicated_driver
                              ? `${location.dedicated_driver.name}${location.dedicated_driver.is_active ? "" : " (Inactive)"}`
                              : "—"}
                          </dd>
                        </div>
                        <div><dt className="text-slate-500">Terminals</dt><dd>{location.terminal_summary.active}/{location.terminal_summary.total} active</dd></div>
                      </dl>
                      <button
                        type="button"
                        onClick={() => toggleStatus(location)}
                        className="mt-4 text-sm font-semibold text-cyan-400"
                      >
                        {location.is_active ? "Deactivate" : "Activate"}
                      </button>
                    </article>
                  ))}
                </div>

                <div className="hidden overflow-x-auto md:block">
                  <table className="w-full text-left text-sm">
                    <thead className="bg-slate-950/60 text-slate-400">
                      <tr>
                        {['Name', 'Unit price', 'Threshold', 'Route', 'Dedicated driver', 'Terminals', 'Status', ''].map((heading) => (
                          <th key={heading} className="px-4 py-3 font-medium">{heading}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800">
                      {locations.map((location) => (
                        <tr key={location.id}>
                          <td className="px-4 py-4">
                            <Link href={`/admin/locations/${location.id}`} className="font-semibold text-cyan-300 hover:text-cyan-200">
                              {location.name}
                            </Link>
                          </td>
                          <td className="px-4 py-4">{money(location.unit_price)}</td>
                          <td className="px-4 py-4">{location.haul_threshold}</td>
                          <td className="px-4 py-4 capitalize">{location.route_type}</td>
                          <td className="px-4 py-4">
                            {location.dedicated_driver
                              ? `${location.dedicated_driver.name}${location.dedicated_driver.is_active ? "" : " (Inactive)"}`
                              : "—"}
                          </td>
                          <td className="px-4 py-4">{location.terminal_summary.active}/{location.terminal_summary.total} active</td>
                          <td className="px-4 py-4"><Status active={location.is_active} /></td>
                          <td className="px-4 py-4">
                            <button type="button" onClick={() => toggleStatus(location)} className="font-semibold text-cyan-400">
                              {location.is_active ? "Deactivate" : "Activate"}
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </section>
        </div>
      </main>
    </ProtectedRoute>
  );
}

function Status({ active }: { active: boolean }) {
  return (
    <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${active ? 'bg-emerald-950 text-emerald-300' : 'bg-slate-800 text-slate-400'}`}>
      {active ? "Active" : "Inactive"}
    </span>
  );
}
