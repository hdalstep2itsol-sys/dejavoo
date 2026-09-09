export type TrailerLoadStatus =
  | "active"
  | "pending_warehouse_count"
  | "completed";

export interface TrailerLoad {
  id: number;
  location_id: number;
  status: TrailerLoadStatus;
  started_at: string;
  calculated_units: string;
  location?: {
    id: number;
    name: string;
    route_type: "open" | "dedicated";
    haul_threshold: string;
  };
  created_by?: {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
  };
  committed_driver: {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
  } | null;
  commitment_source: "dedicated" | "open_claim" | null;
  committed_at: string | null;
  committed_by: {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
  } | null;
  swapped_at: string | null;
  swapped_by: {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
  } | null;
  warehouse_actual_count: number | null;
  warehouse_notes: string | null;
  warehouse_confirmed_at: string | null;
  warehouse_confirmed_by: {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
  } | null;
  created_at: string;
  updated_at: string;
}
