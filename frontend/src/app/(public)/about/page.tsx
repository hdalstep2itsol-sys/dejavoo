import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = {
  title: "About | Spring Back Logistics Hub",
  description:
    "Learn how Spring Back Mattress Recycling uses its private Logistics Hub to coordinate collection, trailer, driver, warehouse, and operational activity.",
};

const capabilities = [
  "Track Dejavoo POS transaction activity",
  "Monitor mattress recycling collection counts",
  "Track trailer loads and capacity",
  "Manage trailer swaps and driver assignments",
  "Record warehouse unloading counts",
  "Send operational notifications and system alerts",
];

export default function AboutPage() {
  return (
    <main>
      <section className="border-b border-slate-800 bg-gradient-to-b from-slate-900 to-slate-950">
        <div className="mx-auto max-w-6xl px-6 py-20 sm:py-28">
          <p className="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
            Spring Back Mattress Recycling
          </p>
          <h1 className="mt-4 max-w-4xl text-4xl font-bold tracking-tight text-white sm:text-6xl">
            Spring Back Logistics Hub
          </h1>
          <p className="mt-6 max-w-3xl text-lg leading-8 text-slate-300">
            A private logistics management platform that helps Spring Back Mattress Recycling coordinate collection activity from point-of-sale transactions through trailer pickup and warehouse unloading.
          </p>
          <div className="mt-9 flex flex-wrap gap-3">
            <Link className="rounded-lg bg-cyan-400 px-5 py-3 font-semibold text-slate-950 transition hover:bg-cyan-300" href="/login">
              Staff Login
            </Link>
            <Link className="rounded-lg border border-slate-700 px-5 py-3 font-semibold text-slate-100 transition hover:border-cyan-400 hover:text-cyan-300" href="/privacy">
              Read Privacy Policy
            </Link>
            <Link className="rounded-lg border border-slate-700 px-5 py-3 font-semibold text-slate-100 transition hover:border-cyan-400 hover:text-cyan-300" href="/terms">
              View Terms
            </Link>
          </div>
        </div>
      </section>

      <section aria-labelledby="platform-functions" className="mx-auto max-w-6xl px-6 py-16 sm:py-20">
        <div className="grid gap-10 lg:grid-cols-[0.8fr_1.2fr]">
          <div>
            <p className="text-sm font-semibold uppercase tracking-[0.18em] text-cyan-400">Operations in one place</p>
            <h2 id="platform-functions" className="mt-3 text-3xl font-semibold text-white">
              Built for day-to-day recycling logistics
            </h2>
            <p className="mt-4 leading-7 text-slate-400">
              The Hub gives authorized teams a shared operational view of locations, active loads, collection progress, assignments, swaps, and completed warehouse counts.
            </p>
          </div>
          <ul className="grid gap-4 sm:grid-cols-2">
            {capabilities.map((capability) => (
              <li key={capability} className="rounded-2xl border border-slate-800 bg-slate-900 p-5 text-slate-200 shadow-lg shadow-black/10">
                <span aria-hidden="true" className="mb-4 flex h-9 w-9 items-center justify-center rounded-lg bg-cyan-400/10 text-cyan-300">✓</span>
                {capability}
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section className="border-t border-slate-800 bg-slate-900/60">
        <div className="mx-auto max-w-6xl px-6 py-14">
          <div className="rounded-2xl border border-slate-700 bg-slate-900 p-7 sm:flex sm:items-center sm:justify-between sm:gap-8">
            <div>
              <h2 className="text-2xl font-semibold text-white">Authorized staff access only</h2>
              <p className="mt-2 max-w-2xl leading-7 text-slate-400">
                Operational features and business records are restricted to accounts created for authorized Spring Back personnel.
              </p>
            </div>
            <Link className="mt-6 inline-flex shrink-0 rounded-lg bg-cyan-400 px-5 py-3 font-semibold text-slate-950 hover:bg-cyan-300 sm:mt-0" href="/login">
              Go to Staff Login
            </Link>
          </div>
        </div>
      </section>
    </main>
  );
}
