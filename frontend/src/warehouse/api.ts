import { apiRequest, initializeCsrf } from "@/lib/api";
import type { TrailerLoad } from "@/trailer-loads/types";

export async function listPendingWarehouseLoads(): Promise<TrailerLoad[]> {
  const response = await apiRequest<{ data: TrailerLoad[] }>(
    "/api/warehouse/trailer-loads",
  );

  return response.data;
}

export async function getPendingWarehouseLoad(loadId: number): Promise<TrailerLoad> {
  const response = await apiRequest<{ data: TrailerLoad }>(
    `/api/warehouse/trailer-loads/${loadId}`,
  );

  return response.data;
}

export async function confirmWarehouseLoad(
  loadId: number,
  actualCount: number,
  notes: string,
): Promise<TrailerLoad> {
  await initializeCsrf();

  const response = await apiRequest<{ data: TrailerLoad }>(
    `/api/warehouse/trailer-loads/${loadId}/confirm`,
    {
      method: "POST",
      body: JSON.stringify({
        actual_count: actualCount,
        notes: notes.trim() || null,
      }),
    },
  );

  return response.data;
}
