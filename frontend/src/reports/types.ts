import type { TrailerLoadStatus } from "@/trailer-loads/types";

export interface ReportFilters {
  locationId: string;
  status: TrailerLoadStatus | "";
  startDate: string;
  endDate: string;
  driverId: string;
}

export interface TrailerLoadReportRow {
  id: number;
  location: { id: number; name: string };
  status: TrailerLoadStatus;
  started_at: string;
  swapped_at: string | null;
  driver: { id: number; name: string; is_active: boolean } | null;
  route_type: "open" | "dedicated";
  calculated_units: string;
  manual_adjustment_units: string;
  operational_units: string;
  warehouse_actual_count: number | null;
  warehouse_variance: string | null;
  warehouse_confirmed_at: string | null;
}

export interface ReportResponse {
  data: TrailerLoadReportRow[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
  filter_options: {
    locations: Array<{ id: number; name: string }>;
    drivers: Array<{ id: number; name: string; is_active: boolean }>;
  };
}
