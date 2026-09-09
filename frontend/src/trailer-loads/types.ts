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
  };
  created_by?: {
    id: number;
    name: string;
    email: string;
  };
  created_at: string;
  updated_at: string;
}
