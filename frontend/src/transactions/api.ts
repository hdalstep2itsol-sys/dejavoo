import { apiRequest } from "@/lib/api";
import type { NormalizedTransaction } from "./types";

export async function listLoadTransactions(
  locationId: number,
  loadId: number,
): Promise<NormalizedTransaction[]> {
  const response = await apiRequest<{ data: NormalizedTransaction[] }>(
    `/api/admin/locations/${locationId}/trailer-loads/${loadId}/transactions`,
  );

  return response.data;
}
