import { apiRequest, initializeCsrf } from "@/lib/api";
import type { TrailerLoad } from "@/trailer-loads/types";

export interface DriverRoutes {
  my_routes: TrailerLoad[];
  open_routes: TrailerLoad[];
}

export async function listDriverRoutes(): Promise<DriverRoutes> {
  const response = await apiRequest<{ data: DriverRoutes }>(
    "/api/driver/trailer-loads",
  );

  return response.data;
}

export async function getDriverRoute(loadId: number): Promise<TrailerLoad> {
  const response = await apiRequest<{ data: TrailerLoad }>(
    `/api/driver/trailer-loads/${loadId}`,
  );

  return response.data;
}

export async function claimDriverRoute(loadId: number): Promise<TrailerLoad> {
  await initializeCsrf();

  const response = await apiRequest<{ data: TrailerLoad }>(
    `/api/driver/trailer-loads/${loadId}/claim`,
    { method: "POST" },
  );

  return response.data;
}

export async function swapDriverTrailer(loadId: number): Promise<{
  swapped_load: TrailerLoad;
  replacement_load: TrailerLoad;
}> {
  await initializeCsrf();

  const response = await apiRequest<{
    data: {
      swapped_load: TrailerLoad;
      replacement_load: TrailerLoad;
    };
  }>(`/api/driver/trailer-loads/${loadId}/swap`, { method: "POST" });

  return response.data;
}
