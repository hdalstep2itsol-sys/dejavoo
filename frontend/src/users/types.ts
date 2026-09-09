import type { UserRole } from "@/auth/types";

export interface ManagedUser {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface CreateUserInput {
  name: string;
  email: string;
  role: UserRole;
  password: string;
  is_active: boolean;
}

export interface UpdateUserInput {
  name: string;
  email: string;
  role: UserRole;
}
