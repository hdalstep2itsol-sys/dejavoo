import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Privacy Policy | Spring Back Logistics Hub",
  description:
    "Privacy Policy for the Spring Back Logistics Hub operated by Spring Back Mattress Recycling.",
};

function PolicySection({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="border-t border-slate-800 pt-8">
      <h2 className="text-2xl font-semibold text-white">{title}</h2>
      <div className="mt-4 space-y-4 leading-7 text-slate-300">{children}</div>
    </section>
  );
}

export default function PrivacyPage() {
  return (
    <main className="mx-auto max-w-4xl px-6 py-16 sm:py-20">
      <header>
        <p className="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">Public policy</p>
        <h1 className="mt-3 text-4xl font-bold tracking-tight text-white sm:text-5xl">Privacy Policy</h1>
        <p className="mt-4 text-slate-400">Last updated: November 18, 2026</p>
        <p className="mt-6 text-lg leading-8 text-slate-300">
          This policy explains how Spring Back Mattress Recycling processes information through the Spring Back Logistics Hub, a private platform used to coordinate mattress recycling logistics.
        </p>
      </header>

      <div className="mt-12 space-y-10">
        <PolicySection title="Who operates the application">
          <p>Spring Back Mattress Recycling operates the Spring Back Logistics Hub. The application is intended for authorized personnel involved in collection, driving, warehouse, and administrative operations.</p>
        </PolicySection>

        <PolicySection title="Information the Hub processes">
          <p>The application processes operational information needed to run the service, including:</p>
          <ul className="list-disc space-y-2 pl-6 marker:text-cyan-400">
            <li>authorized staff names, email addresses, account status, and assigned roles;</li>
            <li>location, terminal, driver assignment, trailer, swap, and warehouse activity;</li>
            <li>provider event and transaction references, transaction amounts used for operational calculations, collection counts, adjustments, statuses, and timestamps;</li>
            <li>warehouse counts and operational notes entered by authorized staff; and</li>
            <li>authentication session information and limited diagnostic records needed to operate and troubleshoot the service.</li>
          </ul>
          <p>The FEED integration is designed to retain normalized operational records and sanitized event metadata rather than raw payment payloads or card and customer details.</p>
        </PolicySection>

        <PolicySection title="How information is used">
          <p>Information is used to authenticate authorized users, apply role-based access, map point-of-sale activity to operating locations, calculate collection progress, coordinate trailer and driver workflows, record warehouse outcomes, produce operational reports, send notifications, and investigate system failures.</p>
          <p>Spring Back does not use Google user data for advertising and does not sell Google user data to third parties.</p>
        </PolicySection>

        <PolicySection title="Service providers and integrations">
          <p>Dejavoo/iPOSpays provides transaction event information used by the Hub for logistics calculations and terminal-to-location resolution. The application may also rely on hosting, database, email-delivery, and related infrastructure providers to operate the service.</p>
          <p>When the Google Gmail sending integration is enabled for an authorized account, the application requests only the <code className="rounded bg-slate-900 px-1.5 py-1 text-sm text-cyan-300">https://www.googleapis.com/auth/gmail.send</code> permission. That permission is used only to send authorized operational emails from the connected account. The integration does not request permission to read, modify, or delete inbox messages.</p>
        </PolicySection>

        <PolicySection title="Google API data and Limited Use">
          <p>The Spring Back Logistics Hub&apos;s use and transfer to any other application of information received from Google APIs will adhere to the <a className="font-medium text-cyan-300 underline decoration-cyan-700 underline-offset-4 hover:text-cyan-200" href="https://developers.google.com/terms/api-services-user-data-policy" rel="noreferrer" target="_blank">Google API Services User Data Policy</a>, including the Limited Use requirements.</p>
          <p>Google user data obtained through the Gmail sending permission is used only to provide the user-facing email-sending function. It is not used for advertising, audience profiling, or sale to third parties.</p>
        </PolicySection>

        <PolicySection title="Security and access control">
          <p>Operational features require an authenticated account. Access is limited by assigned role, and deactivated accounts cannot continue using protected application APIs. Users are responsible for protecting their credentials and promptly reporting suspected unauthorized access.</p>
          <p>No online system can be guaranteed completely secure. Spring Back limits access to personnel and service providers who need information to operate or support the Hub.</p>
        </PolicySection>

        <PolicySection title="Retention and deletion requests">
          <p>Operational records are retained for as long as they are needed for logistics operations, reporting, troubleshooting, and applicable business or legal obligations. Because records may preserve load and transaction history, deletion requests may be subject to operational and recordkeeping requirements.</p>
          <p>The application does not currently provide a public self-service deletion tool. To ask about access, correction, or deletion of information associated with an account, contact Spring Back using the address below.</p>
        </PolicySection>

        <PolicySection title="Third-party services">
          <p>Third-party services process information under their own terms and privacy practices. Spring Back shares or transmits only the information needed to operate the applicable integration or service. Links to third-party sites are provided for reference and are governed by those sites&apos; policies.</p>
        </PolicySection>

        <PolicySection title="Contact">
          <p>Questions or privacy requests may be sent to <a className="font-medium text-cyan-300 hover:text-cyan-200" href="mailto:springback.logistics@gmail.com">springback.logistics@gmail.com</a>.</p>
        </PolicySection>
      </div>
    </main>
  );
}
