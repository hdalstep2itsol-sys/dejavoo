import { apiRequest } from "@/lib/api";
import type { LocationPriceHistory } from "./types";

export async function listLocationPriceHistory(
  locationId: number,
): Promise<LocationPriceHistory[]> {
  const response = await apiRequest<{ data: LocationPriceHistory[] }>(
    `/api/admin/locations/${locationId}/price-history`,
  );

  return response.data;
}
