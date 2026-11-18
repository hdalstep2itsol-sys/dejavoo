import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Terms of Service | Spring Back Logistics Hub",
  description:
    "Terms governing authorized use of the Spring Back Logistics Hub by Spring Back Mattress Recycling personnel.",
};

export default function TermsPage() {
  return (
    <main className="mx-auto max-w-4xl px-6 py-16 sm:py-20">
      <header>
        <p className="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">Authorized use</p>
        <h1 className="mt-3 text-4xl font-bold tracking-tight text-white sm:text-5xl">Terms of Service</h1>
        <p className="mt-6 text-lg leading-8 text-slate-300">
          These terms apply to use of the Spring Back Logistics Hub, a private operational platform for Spring Back Mattress Recycling.
        </p>
      </header>

      <div className="mt-12 space-y-8">
        <section className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
          <h2 className="text-xl font-semibold text-white">Authorized personnel only</h2>
          <p className="mt-3 leading-7 text-slate-300">The Hub is intended only for Spring Back personnel and other users expressly authorized by Spring Back. Users may access only the features and records appropriate to their assigned role.</p>
        </section>
        <section className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
          <h2 className="text-xl font-semibold text-white">Account responsibility</h2>
          <p className="mt-3 leading-7 text-slate-300">Users must protect their login credentials, must not share accounts, and should promptly report suspected unauthorized access. Activity performed through an account should be attributable to its authorized user.</p>
        </section>
        <section className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
          <h2 className="text-xl font-semibold text-white">Accurate operational records</h2>
          <p className="mt-3 leading-7 text-slate-300">Users should enter driver commitments, trailer swaps, warehouse counts, notes, adjustments, and other operational records accurately and correct mistakes through the available application workflows.</p>
        </section>
        <section className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
          <h2 className="text-xl font-semibold text-white">Prohibited use</h2>
          <p className="mt-3 leading-7 text-slate-300">Unauthorized access, attempts to bypass access controls, misuse of operational information, interference with the service, and use of another person&apos;s credentials are prohibited.</p>
        </section>
        <section className="border-t border-slate-800 pt-8">
          <h2 className="text-xl font-semibold text-white">Questions</h2>
          <p className="mt-3 leading-7 text-slate-300">For questions about these terms or access to the Hub, contact <a className="font-medium text-cyan-300 hover:text-cyan-200" href="mailto:springback.logistics@gmail.com">springback.logistics@gmail.com</a>.</p>
        </section>
      </div>
    </main>
  );
}
