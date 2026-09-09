import { apiRequest, initializeCsrf } from "@/lib/api";
import type { CreateUserInput, ManagedUser, UpdateUserInput } from "./types";

async function mutation<T>(path: string, method: string, body: unknown): Promise<T> {
  await initializeCsrf();

  return apiRequest<T>(path, {
    method,
    body: JSON.stringify(body),
  });
}

export async function listUsers(): Promise<ManagedUser[]> {
  const response = await apiRequest<{ data: ManagedUser[] }>("/api/admin/users");
  return response.data;
}

export async function createUser(input: CreateUserInput): Promise<ManagedUser> {
  const response = await mutation<{ data: ManagedUser }>("/api/admin/users", "POST", input);
  return response.data;
}

export async function updateUser(id: number, input: UpdateUserInput): Promise<ManagedUser> {
  const response = await mutation<{ data: ManagedUser }>(
    `/api/admin/users/${id}`,
    "PUT",
    input,
  );
  return response.data;
}

export async function setUserActive(id: number, isActive: boolean): Promise<ManagedUser> {
  const response = await mutation<{ data: ManagedUser }>(
    `/api/admin/users/${id}/status`,
    "PATCH",
    { is_active: isActive },
  );
  return response.data;
}
