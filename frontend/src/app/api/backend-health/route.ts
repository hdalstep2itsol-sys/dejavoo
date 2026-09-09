import { NextResponse } from "next/server";

export const dynamic = "force-dynamic";

export async function GET() {
  const backendUrl = process.env.BACKEND_INTERNAL_URL ?? "http://backend:8000";

  try {
    const response = await fetch(`${backendUrl}/api/health`, {
      cache: "no-store",
    });
    const body = await response.json();

    return NextResponse.json(body, { status: response.status });
  } catch {
    return NextResponse.json(
      { status: "error", service: "dejavoo-backend" },
      { status: 503 },
    );
  }
}
