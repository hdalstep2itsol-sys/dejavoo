import { apiRequest, initializeCsrf } from "@/lib/api";
import type {
  DriverOption,
  Location,
  LocationInput,
  TerminalInput,
  TerminalMapping,
} from "./types";

async function mutation<T>(path: string, method: string, body: unknown): Promise<T> {
  await initializeCsrf();

  return apiRequest<T>(path, {
    method,
    body: JSON.stringify(body),
  });
}

export async function listLocations(): Promise<Location[]> {
  const response = await apiRequest<{ data: Location[] }>("/api/admin/locations");
  return response.data;
}

export async function getLocation(id: string): Promise<Location> {
  const response = await apiRequest<{ data: Location }>(`/api/admin/locations/${id}`);
  return response.data;
}

export async function listDrivers(): Promise<DriverOption[]> {
  const response = await apiRequest<{ data: DriverOption[] }>("/api/admin/drivers");
  return response.data;
}

export async function createLocation(input: LocationInput): Promise<Location> {
  const response = await mutation<{ data: Location }>("/api/admin/locations", "POST", input);
  return response.data;
}

export async function updateLocation(id: number, input: LocationInput): Promise<Location> {
  const response = await mutation<{ data: Location }>(
    `/api/admin/locations/${id}`,
    "PUT",
    input,
  );
  return response.data;
}

export async function setLocationActive(id: number, isActive: boolean): Promise<Location> {
  const response = await mutation<{ data: Location }>(
    `/api/admin/locations/${id}/status`,
    "PATCH",
    { is_active: isActive },
  );
  return response.data;
}

export async function createTerminal(
  locationId: number,
  input: TerminalInput,
): Promise<TerminalMapping> {
  const response = await mutation<{ data: TerminalMapping }>(
    `/api/admin/locations/${locationId}/terminals`,
    "POST",
    input,
  );
  return response.data;
}

export async function updateTerminal(
  locationId: number,
  terminalId: number,
  input: TerminalInput,
): Promise<TerminalMapping> {
  const response = await mutation<{ data: TerminalMapping }>(
    `/api/admin/locations/${locationId}/terminals/${terminalId}`,
    "PUT",
    input,
  );
  return response.data;
}

export async function setTerminalActive(
  locationId: number,
  terminalId: number,
  isActive: boolean,
): Promise<TerminalMapping> {
  const response = await mutation<{ data: TerminalMapping }>(
    `/api/admin/locations/${locationId}/terminals/${terminalId}/status`,
    "PATCH",
    { is_active: isActive },
  );
  return response.data;
}
