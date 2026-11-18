import Link from "next/link";

export function PublicPageShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen bg-slate-950 text-slate-100">
      <header className="border-b border-slate-800 bg-slate-950/95">
        <div className="mx-auto flex max-w-6xl flex-col gap-4 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
          <Link href="/about" className="group flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-cyan-400 font-bold text-slate-950">
              SB
            </span>
            <span>
              <span className="block font-semibold text-white group-hover:text-cyan-300">
                Spring Back Logistics Hub
              </span>
              <span className="block text-xs text-slate-400">
                Mattress recycling operations
              </span>
            </span>
          </Link>

          <nav aria-label="Public navigation" className="flex flex-wrap items-center gap-2 text-sm">
            <Link className="rounded-lg px-3 py-2 text-slate-300 hover:bg-slate-900 hover:text-white" href="/about">
              About
            </Link>
            <Link className="rounded-lg px-3 py-2 text-slate-300 hover:bg-slate-900 hover:text-white" href="/privacy">
              Privacy Policy
            </Link>
            <Link className="rounded-lg px-3 py-2 text-slate-300 hover:bg-slate-900 hover:text-white" href="/terms">
              Terms
            </Link>
            <Link className="rounded-lg bg-cyan-400 px-4 py-2 font-semibold text-slate-950 transition hover:bg-cyan-300" href="/login">
              Staff Login
            </Link>
          </nav>
        </div>
      </header>

      {children}

      <footer className="border-t border-slate-800">
        <div className="mx-auto flex max-w-6xl flex-col gap-4 px-6 py-8 text-sm text-slate-400 sm:flex-row sm:items-center sm:justify-between">
          <p>Spring Back Mattress Recycling</p>
          <nav aria-label="Footer navigation" className="flex flex-wrap gap-x-5 gap-y-2">
            <Link className="hover:text-cyan-300" href="/about">About</Link>
            <Link className="hover:text-cyan-300" href="/privacy">Privacy Policy</Link>
            <Link className="hover:text-cyan-300" href="/terms">Terms of Service</Link>
            <Link className="hover:text-cyan-300" href="/login">Staff Login</Link>
          </nav>
        </div>
      </footer>
    </div>
  );
}
