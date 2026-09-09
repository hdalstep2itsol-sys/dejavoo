"use client";

import { useRouter } from "next/navigation";
import Link from "next/link";
import { useState } from "react";
import { useAuth } from "./AuthProvider";
import { ProtectedRoute } from "./ProtectedRoute";
import { roleLabel, type UserRole } from "./types";
import { ApiError } from "@/lib/api";

export function RolePlaceholder({ role }: { role: UserRole }) {
  const { user, logout } = useAuth();
  const router = useRouter();
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
      setError(
        caught instanceof ApiError
          ? caught.message
          : "Unable to sign out. Please try again.",
      );
      setLoggingOut(false);
    }
  }

  return (
    <ProtectedRoute allowedRole={role}>
      <main className="flex min-h-screen items-center justify-center bg-slate-950 px-6 text-slate-100">
        <section className="w-full max-w-xl rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-2xl">
          <p className="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
            Protected area
          </p>
          <h1 className="mt-3 text-3xl font-semibold">{roleLabel[role]}</h1>
          <dl className="mt-8 grid gap-4 text-sm">
            <div>
              <dt className="text-slate-400">User name</dt>
              <dd className="mt-1 text-lg text-white">{user?.name}</dd>
            </div>
            <div>
              <dt className="text-slate-400">Role</dt>
              <dd className="mt-1 font-mono text-cyan-300">{user?.role}</dd>
            </div>
          </dl>
          <p className="mt-8 text-sm text-slate-400">
            Authentication is working. Application dashboard features are not implemented yet.
          </p>
          {role === "owner_admin" && (
            <div className="mt-6 flex flex-wrap gap-3">
              <Link
                href="/admin/locations"
                className="inline-flex rounded-lg border border-cyan-700 px-4 py-2.5 font-semibold text-cyan-300 hover:bg-cyan-950"
              >
                Manage locations
              </Link>
              <Link
                href="/admin/users"
                className="inline-flex rounded-lg border border-cyan-700 px-4 py-2.5 font-semibold text-cyan-300 hover:bg-cyan-950"
              >
                Manage users
              </Link>
            </div>
          )}
          {error && (
            <p role="alert" className="mt-4 rounded-lg bg-red-950 px-3 py-2 text-sm text-red-200">
              {error}
            </p>
          )}
          <button
            type="button"
            onClick={handleLogout}
            disabled={loggingOut}
            className="mt-8 rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {loggingOut ? "Signing out…" : "Sign out"}
          </button>
        </section>
      </main>
    </ProtectedRoute>
  );
}
