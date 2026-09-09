"use client";

import { FormEvent, useState } from "react";
import { roleLabel, type UserRole } from "@/auth/types";
import type { CreateUserInput, ManagedUser, UpdateUserInput } from "./types";

const roles: UserRole[] = ["owner_admin", "driver", "warehouse_staff"];

export function UserForm({
  user,
  submitting,
  onSubmit,
  onCancel,
}: {
  user?: ManagedUser;
  submitting: boolean;
  onSubmit: (input: CreateUserInput | UpdateUserInput) => Promise<void>;
  onCancel: () => void;
}) {
  const [name, setName] = useState(user?.name ?? "");
  const [email, setEmail] = useState(user?.email ?? "");
  const [role, setRole] = useState<UserRole>(user?.role ?? "driver");
  const [password, setPassword] = useState("");
  const [isActive, setIsActive] = useState(true);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (user) {
      await onSubmit({ name, email, role });
      return;
    }

    await onSubmit({ name, email, role, password, is_active: isActive });
  }

  return (
    <form className="grid gap-5" onSubmit={handleSubmit}>
      <div className="grid gap-5 sm:grid-cols-2">
        <label className="grid gap-2 text-sm font-medium">
          Name
          <input
            required
            maxLength={255}
            value={name}
            onChange={(event) => setName(event.target.value)}
            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
          />
        </label>
        <label className="grid gap-2 text-sm font-medium">
          Email
          <input
            required
            type="email"
            maxLength={255}
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
          />
        </label>
      </div>

      <label className="grid gap-2 text-sm font-medium">
        Role
        <select
          value={role}
          onChange={(event) => setRole(event.target.value as UserRole)}
          className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
        >
          {roles.map((value) => (
            <option key={value} value={value}>{roleLabel[value]}</option>
          ))}
        </select>
      </label>

      {!user && (
        <>
          <label className="grid gap-2 text-sm font-medium">
            Password
            <input
              required
              type="password"
              minLength={12}
              autoComplete="new-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 outline-none focus:border-cyan-400"
            />
            <span className="text-xs font-normal text-slate-400">
              At least 12 characters with upper/lowercase letters, a number, and a symbol.
            </span>
          </label>
          <label className="flex items-center gap-3 text-sm font-medium">
            <input
              type="checkbox"
              checked={isActive}
              onChange={(event) => setIsActive(event.target.checked)}
              className="h-4 w-4 rounded border-slate-600 bg-slate-950"
            />
            Active immediately
          </label>
        </>
      )}

      <div className="flex flex-wrap gap-3">
        <button
          type="submit"
          disabled={submitting}
          className="rounded-lg bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60"
        >
          {submitting ? "Saving..." : user ? "Save user" : "Add user"}
        </button>
        <button
          type="button"
          onClick={onCancel}
          className="rounded-lg border border-slate-700 px-4 py-2.5 font-semibold text-slate-200 hover:bg-slate-800"
        >
          Cancel
        </button>
      </div>
    </form>
  );
}
