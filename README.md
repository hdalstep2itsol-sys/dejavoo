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

The application currently includes authentication, role-based user administration, locations with effective-dated price history, Dejavoo terminal mappings, trailer/load initialization, driver commitments, trailer swaps, warehouse confirmation, provider-independent normalized transaction calculations, append-only manual unit adjustments, operational dashboards, and Owner/Admin reports. It does not include the FEED receiver, raw provider amount mapping, forecasting, or notifications.
