# dejavoo

Local Laravel 11 and Next.js application with MySQL and Docker Compose.

## Start

From PowerShell:

```powershell
cd F:\laravel\dejavoo
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
docker compose up --build -d
docker compose ps
```

Open the frontend at `http://localhost:3000`. The Laravel health endpoint is available at `http://localhost:8080/api/health`.

MySQL is available from the host only at `127.0.0.1:3306`; containers continue to reach it as `mysql:3306`.

## Demo data

No public registration endpoint is provided. To prepare the complete UI demo locally or in a deployed testing environment, set `DEV_TEST_USER_PASSWORD` to a unique password of at least 12 characters. For local Docker, set it in the root `.env`:

```dotenv
DEV_TEST_USER_PASSWORD=replace-with-a-demo-only-password
```

If the Docker stack was already running when this value changed, refresh the backend container with `docker compose up -d --force-recreate backend` before seeding.

Then run:

```powershell
docker compose exec -T backend php artisan dejavoo:seed-demo
```

The demo accounts are:

- Owner/Admin: `owner.admin@example.test`
- Drivers: `mike.driver@example.test`, `john.driver@example.test`, `sarah.driver@example.test`
- Inactive Driver: `inactive.driver@example.test` (cannot log in)
- Warehouse: `warehouse@example.test`, `warehouse.two@example.test`

All active accounts use the password supplied through `DEV_TEST_USER_PASSWORD`. The command works in local, deployed, and production-mode Laravel environments; a missing or short password causes it to fail without seeding. It creates deterministic demo-only locations, fake terminal identifiers, active/pending/completed loads, normalized transactions, manual adjustments, and warehouse confirmations. Active/inactive location and terminal states are UI test scenarios only and are not statements about production operations.

Sign in at `http://localhost:3000/login`. Successful login routes Owner/Admin to `/admin`, Drivers to `/driver`, and Warehouse Staff to `/warehouse`.

After manually testing claims, swaps, or warehouse confirmations, restore the known demo dataset with:

```powershell
docker compose exec -T backend php artisan dejavoo:seed-demo --reset
```

The seeder refuses to take ownership of an existing same-name location unless it already carries its exact demo terminal marker. Reset removes only marked demo-location data and refuses to proceed if unexpected terminals, transactions, adjustments, or price-history records have been added to those locations. It never deletes migration/system tables or unrelated locations.

On a deployed server, configure `DEV_TEST_USER_PASSWORD` in the backend runtime environment and run from the deployed backend directory:

```bash
php artisan dejavoo:seed-demo
php artisan dejavoo:seed-demo --reset
```

After signing in as Owner/Admin, user management is available at `http://localhost:3000/admin/users`. Location and Dejavoo terminal mapping administration is available at `http://localhost:3000/admin/locations`.

Location price changes create effective-dated history entries. Within each location's trailer/load section, Owner/Admin can review normalized transactions and add append-only manual unit adjustments; corrections are recorded as compensating entries rather than edits or deletions.

## Production mail and queued notifications

Operational notifications are queued after database commit. Docker Compose includes a private `queue-worker` service that uses the backend image, application code, database, mail, and operational-alert configuration. It waits for healthy MySQL and restarts automatically without exposing a port. Production can deliver mail through the Gmail API over HTTPS, avoiding SMTP port restrictions. Configure these values only in the server runtime environment:

```dotenv
QUEUE_CONNECTION=database
MAIL_MAILER=gmail-api
MAIL_FROM_ADDRESS=sender@example.com
MAIL_FROM_NAME="Dejavoo Logistics Hub"
GMAIL_CLIENT_ID=
GMAIL_CLIENT_SECRET=
GMAIL_REFRESH_TOKEN=
GMAIL_SENDER_EMAIL=sender@example.com
IPOSPAYS_FEED_ALERT_EMAIL=recipient@example.com
IPOSPAYS_FEED_FAILURE_ALERT_THRESHOLD=3
IPOSPAYS_FEED_FAILURE_ALERT_WINDOW_SECONDS=900
IPOSPAYS_FEED_FAILURE_RECENT_SECONDS=86400
```

`MAIL_FROM_ADDRESS` and `GMAIL_SENDER_EMAIL` must identify the Gmail account associated with the refresh token. OAuth credentials remain only in the server `.env`; they must never be committed or passed on a command line. The transport exchanges the refresh token at Google's OAuth token endpoint, keeps the short-lived access token only in process memory, and sends MIME messages through the Gmail API. SMTP remains available by selecting `MAIL_MAILER=smtp` and configuring the existing `MAIL_*` settings instead.

After deployment, recreate the Compose services with `docker compose up -d --build`; this configuration-cache change needs no migration. Compose runs `php artisan queue:work --tries=3 --timeout=90` continuously in `queue-worker`; no separate host-level worker is needed. Both backend and worker rebuild Laravel configuration from their own runtime environment on every start. Their config cache lives in container-local `/tmp/dejavoo-config.php`, so the bind-mounted `backend/bootstrap/cache/config.php` is never read or shared. Preserve the server's existing FEED environment source (such as its private Compose override or env file), and recreate both services after changing it. Threshold notifications go once per active load to active Owner/Admin users. Swap notifications go once per swapped load to active Warehouse Staff users. Repeated FEED failure alerts go to `IPOSPAYS_FEED_ALERT_EMAIL`, at most once per configured alert window. No provider payload, credentials, card data, or customer data is included in these notifications.

After recreating services, verify the non-secret runtime values in **both** containers without printing credentials (include the server Compose override with `-f` if one is used):

```bash
for service in backend queue-worker; do
  docker compose exec -T "$service" php artisan tinker --execute='if (config("ipospays.feed.enabled") !== true || config("queue.default") !== "database" || config("queue.connections.database.retry_after") !== 120 || app()->getCachedConfigPath() !== "/tmp/dejavoo-config.php") { throw new RuntimeException("Runtime configuration mismatch"); } echo "Runtime configuration verified\n";'
done
```

Do not send a test FEED request as part of this configuration check. A mismatch must be resolved before asking Dejavoo to retry delivery.

After the worker is healthy, send one controlled test message to an approved address (replace only the recipient):

```bash
docker compose exec -T backend php artisan tinker --execute='Illuminate\Support\Facades\Mail::raw("Spring Back Logistics Hub Gmail API delivery test.", function ($message) { $message->to("recipient@example.com")->subject("Spring Back Logistics Hub email test"); });'
```

To create deterministic normalized SALE, REFUND, and VOID samples for an existing trailer/load, run:

```powershell
docker compose exec -T backend php artisan dejavoo:seed-normalized-transactions
```

This command is development-only, is safe to rerun, and refuses to run outside Laravel's `local` environment. It does not create or use real Dejavoo identifiers.

## iPOSpays FEED foundation

The single public webhook endpoint is:

```text
POST /api/webhooks/ipospays/feed
```

For the deployed application, its URL is `https://hub.springbackrecyclingtn.com/api/webhooks/ipospays/feed`. The route is deliberately outside Sanctum and is excluded from SPA CSRF checks only for this exact path. It is protected by dedicated HMAC middleware.

The transaction HMAC and mapping profiles are both available as `dejavoo_feed_v1`. The HMAC profile follows the official field-value canonicalization and generates lowercase HMAC-SHA512 output. The mapping profile accepts only `eventType=Transaction`, maps supported payment operations from top-level `subEventType`, uses `data.amount`, resolves `data.tpn`/`data.termId`, and converts `data.txDate` plus `data.txTime` from the configured provider timezone to UTC. The endpoint remains disabled by default; raw payloads and sensitive payment/customer fields are never persisted.

Configure these backend runtime variables without committing their values:

```dotenv
IPOSPAYS_FEED_ENABLED=false
IPOSPAYS_FEED_DIAGNOSTIC_MODE=false
IPOSPAYS_FEED_HMAC_SECRET=
IPOSPAYS_FEED_HMAC_PROFILE=unfinalized
IPOSPAYS_FEED_MAPPING_PROFILE=unfinalized
IPOSPAYS_FEED_TIMEZONE=America/New_York
IPOSPAYS_FEED_MAX_PAYLOAD_BYTES=262144
```

For the controlled live transaction retest, set both profile values to `dejavoo_feed_v1`, keep the provided secret only in the server environment, set the provider timezone, clear/cache Laravel configuration, and then enable the FEED. `SALE`, `REFUND`, `VOID SALE`, and `VOID` are supported. Settlement events and ambiguous transaction sub-events are acknowledged as unsupported without creating normalized transactions. Unknown, inactive, or conflicting terminal mappings cannot create normalized transactions.

The first and update postings are correlated by `data.transactionId`. The first supported posting creates the transaction; a later posting with the same immutable logistics fields is acknowledged idempotently and cannot double-count units. The existing `(source, external_transaction_id)` uniqueness guard remains authoritative with source `ipospays_feed`.

Safe local checks that do not require or reveal a real key:

```powershell
docker compose exec -T backend php artisan test --filter=IpospaysFeedWebhookTest
curl.exe -i -X POST http://localhost:8080/api/webhooks/ipospays/feed -H "Content-Type: application/json" -d "{}"
```

The request above must return a non-2xx `hmac_signature_missing` response. Do not place a real HMAC secret, signature, canonical string, or provider payload in source files, command history, logs, or test fixtures.

### Temporary FEED diagnostics

Setting `IPOSPAYS_FEED_DIAGNOSTIC_MODE=true` observes connection attempts on the existing exact FEED endpoint before HMAC rejection. It does not bypass authentication, return a success response, map provider transactions, or update operational units. `IPOSPAYS_FEED_ENABLED` may remain `false` while diagnostics are active.

The dedicated 14-day rotating log is written as `storage/logs/ipospays-feed-YYYY-MM-DD.log`. It contains an internal observation ID, timestamps, method, path, content type/length, capped User-Agent, capped header names, JSON parse state, capped top-level field names and nested key paths up to depth three, top-level signature presence, truncation indicators, and the fail-closed authentication outcome. It never contains header values, payload values, the raw body, the signature value, or the HMAC secret.

On the deployed server:

1. Set `IPOSPAYS_FEED_DIAGNOSTIC_MODE=true` and the provided `IPOSPAYS_FEED_HMAC_SECRET` in the backend runtime environment. For a diagnostics-only observation keep mapping fail-closed with `IPOSPAYS_FEED_MAPPING_PROFILE=unfinalized`; for the controlled transaction retest use `dejavoo_feed_v1` for both profiles and set `IPOSPAYS_FEED_TIMEZONE=America/New_York`.
2. Ensure the PHP/web process can write to `backend/storage/logs`.
3. Recreate `backend` and `queue-worker` with Compose so each rebuilds its isolated configuration cache from the updated runtime environment. Run the non-secret verification above.
4. Monitor the current daily file with `tail -f storage/logs/ipospays-feed-$(date +%F).log` while Merchant Services triggers a connection attempt.
5. Confirm the request structure and authentication outcome without sharing or copying any secret or payload values.
6. After the observation window, set `IPOSPAYS_FEED_DIAGNOSTIC_MODE=false` in the server's existing private environment source and recreate both services again.

Diagnostic logs must remain private server-side operational data and should not be exposed through the web server.

## Stop

```powershell
cd F:\laravel\dejavoo
docker compose down
```

MySQL data is kept in the `dejavoo_mysql_data` Docker volume. To stop the environment and deliberately remove that local database volume, use `docker compose down -v`.

## Verification commands

```powershell
docker compose exec -T backend php artisan --version
docker compose exec -T backend php artisan tinker --execute="dump(DB::select('SELECT 1 AS connected'));"
docker compose exec -T backend php artisan test
docker compose exec -T frontend npm run lint
docker compose exec -T frontend npm run type-check
docker compose exec -T frontend npm run build
Invoke-RestMethod http://localhost:8080/api/health
Invoke-RestMethod http://localhost:3000/api/backend-health
```

The application currently includes authentication, role-based user administration, locations with effective-dated price history, Dejavoo terminal mappings, trailer/load initialization, driver commitments, trailer swaps, warehouse confirmation, provider-independent normalized transaction calculations, append-only manual unit adjustments, operational dashboards, Owner/Admin reports, and the fail-closed FEED receipt/processing foundation described above. It does not yet accept real FEED transactions or implement provider amount/timestamp/type mapping, forecasting, or notifications.
