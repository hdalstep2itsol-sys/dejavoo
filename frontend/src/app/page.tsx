"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/auth/AuthProvider";
import { roleHome } from "@/auth/types";

export default function Home() {
  const { user, loading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (loading) {
      return;
    }

    router.replace(user ? roleHome[user.role] : "/login");
  }, [loading, router, user]);

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-950 text-slate-300">
      Checking your session…
    </main>
  );
}
