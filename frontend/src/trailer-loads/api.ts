import { apiRequest, initializeCsrf } from "@/lib/api";
import type { TrailerLoad } from "./types";

export async function getCurrentTrailerLoad(
  locationId: number,
): Promise<TrailerLoad | null> {
  const response = await apiRequest<{ data: TrailerLoad | null }>(
    `/api/admin/locations/${locationId}/trailer-loads/current`,
  );

  return response.data;
}

export async function listTrailerLoads(locationId: number): Promise<TrailerLoad[]> {
  const response = await apiRequest<{ data: TrailerLoad[] }>(
    `/api/admin/locations/${locationId}/trailer-loads`,
  );

  return response.data;
}

export async function initializeTrailerLoad(
  locationId: number,
  startedAt: string,
): Promise<TrailerLoad> {
  await initializeCsrf();

  const response = await apiRequest<{ data: TrailerLoad }>(
    `/api/admin/locations/${locationId}/trailer-loads`,
    {
      method: "POST",
      body: JSON.stringify({ started_at: startedAt }),
    },
  );

  return response.data;
}

export async function assignTrailerLoadDriver(
  locationId: number,
  loadId: number,
  driverId: number,
): Promise<TrailerLoad> {
  await initializeCsrf();

  const response = await apiRequest<{ data: TrailerLoad }>(
    `/api/admin/locations/${locationId}/trailer-loads/${loadId}/commitment`,
    {
      method: "PUT",
      body: JSON.stringify({ driver_id: driverId }),
    },
  );

  return response.data;
}

export async function removeTrailerLoadDriver(
  locationId: number,
  loadId: number,
): Promise<TrailerLoad> {
  await initializeCsrf();

  const response = await apiRequest<{ data: TrailerLoad }>(
    `/api/admin/locations/${locationId}/trailer-loads/${loadId}/commitment`,
    { method: "DELETE" },
  );

  return response.data;
}
