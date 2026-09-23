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

The provider documentation indicates HMAC-SHA512 with lowercase hexadecimal output, a JSON `signature` field excluded from signing, and field-based canonicalization. Its published examples contain unresolved case-sensitive field and timestamp inconsistencies, so no production canonicalization algorithm or payload mapping has been guessed. The endpoint therefore remains disabled and fail-closed: it cannot accept a transaction until both a confirmed HMAC profile and a confirmed mapping profile are implemented. If HMAC is finalized before mapping, an authenticated unmappable delivery creates only a metadata-only `pending_provider_mapping` receipt (internal ID, status, error category, count, and timestamps); no provider values or raw payload are retained.

Configure these backend runtime variables without committing their values:

```dotenv
IPOSPAYS_FEED_ENABLED=false
IPOSPAYS_FEED_DIAGNOSTIC_MODE=false
IPOSPAYS_FEED_HMAC_SECRET=
IPOSPAYS_FEED_HMAC_PROFILE=unfinalized
IPOSPAYS_FEED_MAPPING_PROFILE=unfinalized
IPOSPAYS_FEED_MAX_PAYLOAD_BYTES=262144
```

Before activation, obtain from Dejavoo/iPOSpays:

- A real signed payload/test vector and the exact ordered field-based canonicalization rules, including null, missing-field, whitespace, encoding, nested-field, and number formatting behavior.
- Authoritative case-sensitive payload field names, especially `termId`/`TermId`, `mid`/`Mid`, transaction date/time, and L2/L3 fields.
- The provider event/request identifier and transaction identifier fields, plus initial/update delivery behavior.
- The correct business amount (`amount` versus `baseAmount`), SALE/REFUND/VOID values and sign rules.
- The authoritative transaction timestamp, timezone, acknowledgement body/status, and retry/burst contract.

Once those items are confirmed, add a named implementation of `IpospaysFeedHmacProfile`, register it with `IpospaysFeedHmacVerifier`, add the matching isolated payload mapper, verify both with the provider's signed test vector, configure the two profile names and secret, and only then set `IPOSPAYS_FEED_ENABLED=true`. Unknown, inactive, or conflicting terminal mappings remain non-processing outcomes, and normalized transactions continue to use the existing `(source, external_transaction_id)` uniqueness guard with source `ipospays_feed`.

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

1. Set `IPOSPAYS_FEED_DIAGNOSTIC_MODE=true` and the provided `IPOSPAYS_FEED_HMAC_SECRET` in the backend runtime environment. Keep `IPOSPAYS_FEED_ENABLED=false` and both profile values `unfinalized` until the provider contract is confirmed.
2. Ensure the PHP/web process can write to `backend/storage/logs`.
3. From the deployed backend directory, run `php artisan config:cache`.
4. Monitor the current daily file with `tail -f storage/logs/ipospays-feed-$(date +%F).log` while Merchant Services triggers a connection attempt.
5. Confirm the request structure and authentication outcome without sharing or copying any secret or payload values.
6. After the observation window, set `IPOSPAYS_FEED_DIAGNOSTIC_MODE=false` and run `php artisan config:cache` again.

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
