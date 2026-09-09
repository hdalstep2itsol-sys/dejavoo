import type { DriverOption } from "@/locations/types";
import type { TrailerLoad } from "@/trailer-loads/types";

export interface AdminDashboardSummary {
  active_locations: number;
  ready_loads: number;
  awaiting_warehouse_count: number;
  open_unclaimed_active_loads: number;
}

export interface AdminDashboardLocation {
  id: number;
  name: string;
  route_type: "open" | "dedicated";
  haul_threshold: string;
  dedicated_driver: DriverOption | null;
  active_load: TrailerLoad | null;
}

export interface AdminDashboardData {
  summary: AdminDashboardSummary;
  locations: AdminDashboardLocation[];
}

export interface LoadHistoryData {
  loads: TrailerLoad[];
  locations: Array<{ id: number; name: string }>;
}

export interface WarehouseDashboardData {
  summary: { awaiting_warehouse_count: number };
  pending: TrailerLoad[];
  recent_confirmed: TrailerLoad[];
}
