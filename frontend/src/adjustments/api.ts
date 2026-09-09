import { apiRequest, initializeCsrf } from "@/lib/api";
import type { TrailerLoadAdjustment } from "./types";

function path(locationId: number, loadId: number): string {
  return `/api/admin/locations/${locationId}/trailer-loads/${loadId}/adjustments`;
}

export async function listLoadAdjustments(
  locationId: number,
  loadId: number,
): Promise<TrailerLoadAdjustment[]> {
  const response = await apiRequest<{ data: TrailerLoadAdjustment[] }>(
    path(locationId, loadId),
  );

  return response.data;
}

export async function createLoadAdjustment(
  locationId: number,
  loadId: number,
  unitDelta: string,
  reason: string,
): Promise<TrailerLoadAdjustment> {
  await initializeCsrf();
  const response = await apiRequest<{ data: TrailerLoadAdjustment }>(
    path(locationId, loadId),
    {
      method: "POST",
      body: JSON.stringify({ unit_delta: unitDelta, reason }),
    },
  );

  return response.data;
}
