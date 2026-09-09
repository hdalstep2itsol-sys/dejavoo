export function AdminPlaceholder({ title }: { title: string }) {
  return (
    <main className="min-h-screen px-4 py-8 sm:px-6 lg:px-8">
      <div className="mx-auto max-w-4xl">
        <h1 className="text-3xl font-semibold">{title}</h1>
        <section className="mt-6 rounded-2xl border border-dashed border-slate-700 bg-slate-900 p-8">
          <p className="font-semibold text-white">This module is not configured yet.</p>
          <p className="mt-2 text-slate-400">No placeholder data or actions are shown.</p>
        </section>
      </div>
    </main>
  );
}
