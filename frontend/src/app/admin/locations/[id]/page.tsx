"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { ProtectedRoute } from "@/auth/ProtectedRoute";
import {
  getLocation,
  listDrivers,
  setLocationActive,
  updateLocation,
} from "@/locations/api";
import { requestErrorMessage } from "@/locations/errors";
import { LocationForm } from "@/locations/LocationForm";
import { TerminalManager } from "@/locations/TerminalManager";
import type { DriverOption, Location, LocationInput } from "@/locations/types";
import { TrailerLoadPanel } from "@/trailer-loads/TrailerLoadPanel";
import { LocationPriceHistoryPanel } from "@/price-history/LocationPriceHistoryPanel";

export default function LocationDetailPage() {
  const params = useParams<{ id: string }>();
  const [location, setLocation] = useState<Location | null>(null);
  const [drivers, setDrivers] = useState<DriverOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    try {
      const [locationData, driverData] = await Promise.all([
        getLocation(params.id),
        listDrivers(),
      ]);
      setLocation(locationData);
      setDrivers(driverData);
      setError("");
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setLoading(false);
    }
  }, [params.id]);

  useEffect(() => {
    let cancelled = false;

    void Promise.all([getLocation(params.id), listDrivers()])
      .then(([locationData, driverData]) => {
        if (!cancelled) {
          setLocation(locationData);
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
  }, [params.id]);

  async function save(input: LocationInput) {
    if (!location) return;

    setSaving(true);
    setError("");
    try {
      setLocation(await updateLocation(location.id, input));
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSaving(false);
    }
  }

  async function toggleStatus() {
    if (!location) return;

    setSaving(true);
    setError("");
    try {
      setLocation(await setLocationActive(location.id, !location.is_active));
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSaving(false);
    }
  }

  const locationDrivers =
    location?.dedicated_driver &&
    !drivers.some((driver) => driver.id === location.dedicated_driver?.id)
      ? [location.dedicated_driver, ...drivers]
      : drivers;

  return (
    <ProtectedRoute allowedRole="owner_admin">
      <main className="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-5xl">
          <Link href="/admin/locations" className="text-sm text-cyan-400 hover:text-cyan-300">
            ← Locations
          </Link>

          {error && (
            <p role="alert" className="mt-6 rounded-lg bg-red-950 px-4 py-3 text-red-200">
              {error}
            </p>
          )}

          {loading ? (
            <p className="mt-8 text-slate-400">Loading location…</p>
          ) : !location ? (
            <p className="mt-8 text-slate-400">Location could not be loaded.</p>
          ) : (
            <div className="mt-6 grid gap-8">
              <section className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                  <div>
                    <p className="text-sm font-semibold uppercase tracking-[0.16em] text-cyan-400">
                      Location details
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold">{location.name}</h1>
                  </div>
                  <button
                    type="button"
                    disabled={saving}
                    onClick={toggleStatus}
                    className={`rounded-lg px-4 py-2.5 font-semibold disabled:opacity-60 ${
                      location.is_active
                        ? "border border-red-900 text-red-300 hover:bg-red-950"
                        : "bg-emerald-400 text-slate-950 hover:bg-emerald-300"
                    }`}
                  >
                    {location.is_active ? "Deactivate location" : "Activate location"}
                  </button>
                </div>

                <LocationForm
                  key={location.updated_at}
                  drivers={locationDrivers}
                  initialValue={{
                    name: location.name,
                    unit_price: location.unit_price,
                    haul_threshold: location.haul_threshold,
                    route_type: location.route_type,
                    dedicated_driver_id: location.dedicated_driver?.id ?? null,
                  }}
                  submitLabel="Save location"
                  submitting={saving}
                  onSubmit={save}
                />
              </section>

              <LocationPriceHistoryPanel
                key={`${location.id}-${location.unit_price}-${location.updated_at}`}
                locationId={location.id}
              />

              <TrailerLoadPanel location={location} drivers={drivers} />

              <TerminalManager
                locationId={location.id}
                terminals={location.terminals ?? []}
                onChanged={load}
              />
            </div>
          )}
        </div>
      </main>
    </ProtectedRoute>
  );
}
