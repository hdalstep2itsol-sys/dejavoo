export interface LocationPriceHistory {
  id: number;
  location_id: number;
  unit_price: string;
  effective_from: string;
  created_by: {
    id: number;
    name: string;
  } | null;
  created_at: string;
}
