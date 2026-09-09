export type UserRole = "owner_admin" | "driver" | "warehouse_staff";

export interface AuthenticatedUser {
  id: number;
  name: string;
  email: string;
  role: UserRole;
}

export const roleHome: Record<UserRole, string> = {
  owner_admin: "/admin",
  driver: "/driver",
  warehouse_staff: "/warehouse",
};

export const roleLabel: Record<UserRole, string> = {
  owner_admin: "Owner/Admin",
  driver: "Driver",
  warehouse_staff: "Warehouse Staff",
};
