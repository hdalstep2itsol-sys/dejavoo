export type LocationRouteType = "open" | "dedicated";

export interface DriverOption {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
}

export interface TerminalMapping {
  id: number;
  location_id: number;
  tpn: string | null;
  term_id: string | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface Location {
  id: number;
  name: string;
  unit_price: string;
  haul_threshold: string;
  route_type: LocationRouteType;
  dedicated_driver: DriverOption | null;
  is_active: boolean;
  terminal_summary: {
    total: number;
    active: number;
    inactive: number;
  };
  terminals?: TerminalMapping[];
  created_at: string;
  updated_at: string;
}

export interface LocationInput {
  name: string;
  unit_price: string;
  haul_threshold: string;
  route_type: LocationRouteType;
  dedicated_driver_id: number | null;
}

export interface TerminalInput {
  tpn: string | null;
  term_id: string | null;
}
