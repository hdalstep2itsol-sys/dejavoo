export interface TrailerLoadAdjustment {
  id: number;
  trailer_load_id: number;
  unit_delta: string;
  reason: string;
  created_by: {
    id: number;
    name: string;
  };
  created_at: string;
}
