import { PublicPageShell } from "@/public/PublicPageShell";

export default function PublicLayout({ children }: { children: React.ReactNode }) {
  return <PublicPageShell>{children}</PublicPageShell>;
}
