export type NormalizedTransactionType = "sale" | "refund" | "void";

export interface NormalizedTransaction {
  id: number;
  location_id: number;
  trailer_load_id: number;
  transaction_type: NormalizedTransactionType;
  business_amount: string;
  unit_price_snapshot: string;
  unit_delta: string;
  occurred_at: string;
  source: string;
}
