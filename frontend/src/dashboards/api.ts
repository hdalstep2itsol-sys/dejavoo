import { apiRequest } from "@/lib/api";
import type { TrailerLoad, TrailerLoadStatus } from "@/trailer-loads/types";
import type {
  AdminDashboardData,
  LoadHistoryData,
} from "./types";

export async function getAdminDashboard(): Promise<AdminDashboardData> {
  const response = await apiRequest<{ data: AdminDashboardData }>("/api/admin/dashboard");
  return response.data;
}

export async function getAdminRoutes(): Promise<TrailerLoad[]> {
  const response = await apiRequest<{ data: TrailerLoad[] }>("/api/admin/routes");
  return response.data;
}

export async function getAdminPendingLoads(): Promise<TrailerLoad[]> {
  const response = await apiRequest<{ data: TrailerLoad[] }>(
    "/api/admin/warehouse-pending",
  );
  return response.data;
}

export async function getAdminLoadHistory(filters: {
  locationId?: string;
  status?: Exclude<TrailerLoadStatus, "active"> | "";
}): Promise<LoadHistoryData> {
  const params = new URLSearchParams();
  if (filters.locationId) params.set("location_id", filters.locationId);
  if (filters.status) params.set("status", filters.status);

  const query = params.size > 0 ? `?${params.toString()}` : "";
  const response = await apiRequest<{ data: LoadHistoryData }>(
    `/api/admin/load-history${query}`,
  );
  return response.data;
}
