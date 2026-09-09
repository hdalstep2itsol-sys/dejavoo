export type TrailerLoadStatus =
  | "active"
  | "pending_warehouse_count"
  | "completed";

export interface TrailerLoad {
  id: number;
  location_id: number;
  status: TrailerLoadStatus;
  started_at: string;
  location?: {
    id: number;
    name: string;
    route_type: "open" | "dedicated";
  };
  created_by?: {
    id: number;
    name: string;
    email: string;
  };
  committed_driver: {
    id: number;
    name: string;
    email: string;
  } | null;
  commitment_source: "dedicated" | "open_claim" | null;
  committed_at: string | null;
  committed_by: {
    id: number;
    name: string;
    email: string;
  } | null;
  swapped_at: string | null;
  swapped_by: {
    id: number;
    name: string;
    email: string;
  } | null;
  warehouse_actual_count: number | null;
  warehouse_notes: string | null;
  warehouse_confirmed_at: string | null;
  warehouse_confirmed_by: {
    id: number;
    name: string;
    email: string;
  } | null;
  created_at: string;
  updated_at: string;
}
