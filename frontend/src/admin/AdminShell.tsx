"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useState } from "react";
import { ProtectedRoute } from "@/auth/ProtectedRoute";
import { useAuth } from "@/auth/AuthProvider";
import { requestErrorMessage } from "@/locations/errors";

const navigation = [
  ["Dashboard", "/admin"],
  ["Locations", "/admin/locations"],
  ["Routes / Assignments", "/admin/routes"],
  ["Pending Warehouse Counts", "/admin/warehouse-pending"],
  ["Load History", "/admin/load-history"],
  ["Users", "/admin/users"],
  ["Reports", "/admin/reports"],
  ["Notifications", "/admin/notifications"],
  ["Settings", "/admin/settings"],
] as const;

export function AdminShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const { user, logout } = useAuth();
  const [loggingOut, setLoggingOut] = useState(false);
  const [error, setError] = useState("");

  async function handleLogout() {
    setLoggingOut(true);
    setError("");
    try {
      await logout();
      router.replace("/login");
      router.refresh();
    } catch (caught) {
      setError(requestErrorMessage(caught));
      setLoggingOut(false);
    }
  }

  return (
    <ProtectedRoute allowedRole="owner_admin">
      <div className="min-h-screen bg-slate-950 text-slate-100 lg:flex">
        <aside className="border-b border-slate-800 bg-slate-900/90 px-4 py-5 lg:sticky lg:top-0 lg:h-screen lg:w-72 lg:border-b-0 lg:border-r lg:px-5">
          <Link href="/admin" className="text-xl font-bold tracking-tight text-white">
            Dejavoo Operations
          </Link>
          <nav className="mt-5 flex gap-2 overflow-x-auto pb-2 lg:grid lg:overflow-visible" aria-label="Owner administration">
            {navigation.map(([label, href]) => {
              const active = href === "/admin" ? pathname === href : pathname.startsWith(href);
              return (
                <Link
                  key={href}
                  href={href}
                  className={`whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition ${
                    active
                      ? "bg-cyan-400 text-slate-950"
                      : "text-slate-300 hover:bg-slate-800 hover:text-white"
                  }`}
                >
                  {label}
                </Link>
              );
            })}
          </nav>
          <div className="mt-5 border-t border-slate-800 pt-4 text-sm lg:absolute lg:bottom-5 lg:left-5 lg:right-5">
            <p className="font-semibold text-white">{user?.name}</p>
            <p className="text-slate-500">Owner / Admin profile</p>
            {error && <p className="mt-2 text-red-300">{error}</p>}
            <button
              type="button"
              onClick={handleLogout}
              disabled={loggingOut}
              className="mt-3 w-full rounded-lg border border-slate-700 px-3 py-2 font-semibold hover:bg-slate-800 disabled:opacity-60"
            >
              {loggingOut ? "Signing out..." : "Logout"}
            </button>
          </div>
        </aside>
        <div className="min-w-0 flex-1">{children}</div>
      </div>
    </ProtectedRoute>
  );
}
