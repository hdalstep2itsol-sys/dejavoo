import { displayUnits, titleCase } from "./format";

export function OperationalBadge({ status }: { status: "active" | "ready" }) {
  return (
    <span
      className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${
        status === "ready"
          ? "bg-emerald-950 text-emerald-300"
          : "bg-cyan-950 text-cyan-300"
      }`}
    >
      {titleCase(status)}
    </span>
  );
}

export function LoadProgress({ percentage }: { percentage: string }) {
  const numeric = Number(percentage);
  const visualWidth = Math.min(Math.max(numeric, 0), 100);

  return (
    <div className="min-w-28">
      <div className="mb-1 text-sm font-medium">{displayUnits(percentage)}%</div>
      <div className="h-2 overflow-hidden rounded-full bg-slate-800">
        <div
          className="h-full rounded-full bg-cyan-400"
          style={{ width: `${visualWidth}%` }}
        />
      </div>
    </div>
  );
}
