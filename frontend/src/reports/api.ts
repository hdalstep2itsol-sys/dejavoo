import { API_URL, ApiError, apiRequest } from "@/lib/api";
import type { ReportFilters, ReportResponse } from "./types";

function queryString(filters: ReportFilters, page?: number): string {
  const params = new URLSearchParams();
  if (filters.locationId) params.set("location_id", filters.locationId);
  if (filters.status) params.set("status", filters.status);
  if (filters.startDate) params.set("start_date", filters.startDate);
  if (filters.endDate) params.set("end_date", filters.endDate);
  if (filters.driverId) params.set("driver_id", filters.driverId);
  if (page) params.set("page", String(page));
  return params.toString();
}

export async function getTrailerLoadReport(
  filters: ReportFilters,
  page: number,
): Promise<ReportResponse> {
  const query = queryString(filters, page);
  return apiRequest<ReportResponse>(`/api/admin/reports?${query}`);
}

export async function downloadTrailerLoadReport(
  format: "csv" | "xlsx",
  filters: ReportFilters,
): Promise<void> {
  const query = queryString(filters);
  const response = await fetch(`${API_URL}/api/admin/reports/export/${format}?${query}`, {
    credentials: "include",
    cache: "no-store",
    headers: { Accept: format === "csv" ? "text/csv" : "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" },
  });

  if (!response.ok) {
    const payload = (await response.json().catch(() => ({}))) as { message?: string };
    throw new ApiError(response.status, payload.message ?? "Unable to export the report.");
  }

  const disposition = response.headers.get("Content-Disposition") ?? "";
  const match = disposition.match(/filename\*?=(?:UTF-8''|\")?([^\";]+)/i);
  const filename = match ? decodeURIComponent(match[1]) : `trailer-load-report.${format}`;
  const url = URL.createObjectURL(await response.blob());
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
}
