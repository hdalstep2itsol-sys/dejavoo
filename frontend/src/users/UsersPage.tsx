"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { ProtectedRoute } from "@/auth/ProtectedRoute";
import { roleLabel } from "@/auth/types";
import { requestErrorMessage } from "@/locations/errors";
import { createUser, listUsers, setUserActive, updateUser } from "./api";
import { UserForm } from "./UserForm";
import type { CreateUserInput, ManagedUser, UpdateUserInput } from "./types";

export function UsersPage() {
  const [users, setUsers] = useState<ManagedUser[]>([]);
  const [editing, setEditing] = useState<ManagedUser | null>(null);
  const [adding, setAdding] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;

    void listUsers()
      .then((data) => {
        if (!cancelled) setUsers(data);
      })
      .catch((caught) => {
        if (!cancelled) setError(requestErrorMessage(caught));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  function replaceUser(updated: ManagedUser) {
    setUsers((current) =>
      current
        .map((user) => (user.id === updated.id ? updated : user))
        .sort((a, b) => a.name.localeCompare(b.name)),
    );
  }

  async function save(input: CreateUserInput | UpdateUserInput) {
    setSaving(true);
    setError("");
    try {
      if (editing) {
        replaceUser(await updateUser(editing.id, input as UpdateUserInput));
        setEditing(null);
      } else {
        const created = await createUser(input as CreateUserInput);
        setUsers((current) => [...current, created].sort((a, b) => a.name.localeCompare(b.name)));
        setAdding(false);
      }
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSaving(false);
    }
  }

  async function toggleStatus(user: ManagedUser) {
    setSaving(true);
    setError("");
    try {
      replaceUser(await setUserActive(user.id, !user.is_active));
    } catch (caught) {
      setError(requestErrorMessage(caught));
    } finally {
      setSaving(false);
    }
  }

  return (
    <ProtectedRoute allowedRole="owner_admin">
      <main className="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-6xl">
          <Link href="/admin" className="text-sm text-cyan-400 hover:text-cyan-300">
            &larr; Admin home
          </Link>
          <div className="mt-5 flex flex-wrap items-end justify-between gap-4">
            <div>
              <p className="text-sm font-semibold uppercase tracking-[0.16em] text-cyan-400">
                Owner/Admin
              </p>
              <h1 className="mt-2 text-3xl font-semibold">Users</h1>
              <p className="mt-2 text-slate-400">Manage access without deleting historical records.</p>
            </div>
            <button
              type="button"
              onClick={() => { setEditing(null); setAdding(true); setError(""); }}
              className="rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 hover:bg-cyan-300"
            >
              Add user
            </button>
          </div>

          {error && (
            <p role="alert" className="mt-6 rounded-lg bg-red-950 px-4 py-3 text-red-200">{error}</p>
          )}

          {(adding || editing) && (
            <section className="mt-6 rounded-2xl border border-slate-800 bg-slate-900 p-6">
              <h2 className="mb-5 text-xl font-semibold">{editing ? "Edit user" : "Add user"}</h2>
              <UserForm
                key={editing?.id ?? "new"}
                user={editing ?? undefined}
                submitting={saving}
                onSubmit={save}
                onCancel={() => { setAdding(false); setEditing(null); }}
              />
            </section>
          )}

          <section className="mt-6 overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">
            {loading ? (
              <p className="p-6 text-slate-400">Loading users...</p>
            ) : users.length === 0 ? (
              <p className="p-6 text-slate-400">No users found.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] text-left text-sm">
                  <thead className="bg-slate-800/70 text-slate-300">
                    <tr>
                      <th className="px-4 py-3 font-medium">Name</th>
                      <th className="px-4 py-3 font-medium">Email</th>
                      <th className="px-4 py-3 font-medium">Role</th>
                      <th className="px-4 py-3 font-medium">Status</th>
                      <th className="px-4 py-3 text-right font-medium">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800">
                    {users.map((user) => (
                      <tr key={user.id}>
                        <td className="px-4 py-4 font-medium text-white">{user.name}</td>
                        <td className="px-4 py-4 text-slate-300">{user.email}</td>
                        <td className="px-4 py-4 text-slate-300">{roleLabel[user.role]}</td>
                        <td className="px-4 py-4">
                          <span className={user.is_active ? "text-emerald-300" : "text-slate-400"}>
                            {user.is_active ? "Active" : "Inactive"}
                          </span>
                        </td>
                        <td className="px-4 py-4">
                          <div className="flex justify-end gap-2">
                            <button
                              type="button"
                              onClick={() => { setAdding(false); setEditing(user); setError(""); }}
                              className="rounded-lg border border-slate-700 px-3 py-2 font-semibold hover:bg-slate-800"
                            >
                              Edit
                            </button>
                            <button
                              type="button"
                              disabled={saving}
                              onClick={() => void toggleStatus(user)}
                              className={`rounded-lg px-3 py-2 font-semibold disabled:opacity-60 ${
                                user.is_active
                                  ? "border border-red-900 text-red-300 hover:bg-red-950"
                                  : "border border-emerald-800 text-emerald-300 hover:bg-emerald-950"
                              }`}
                            >
                              {user.is_active ? "Deactivate" : "Activate"}
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </div>
      </main>
    </ProtectedRoute>
  );
}
