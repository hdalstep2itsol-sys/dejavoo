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

## Development users

No public registration endpoint is provided. To create one local test user for each role, set a unique password of at least 12 characters in the root `.env`:

```dotenv
DEV_TEST_USER_PASSWORD=replace-with-a-local-only-password
```

Then run:

```powershell
docker compose exec -T backend php artisan db:seed --class=DevelopmentUserSeeder
```

The development-only accounts are:

- `owner.admin@example.test` → `http://localhost:3000/admin`
- `driver@example.test` → `http://localhost:3000/driver`
- `warehouse@example.test` → `http://localhost:3000/warehouse`

All three use the password supplied through `DEV_TEST_USER_PASSWORD`. The seeder refuses to run outside the local environment.

After signing in as Owner/Admin, location and Dejavoo terminal mapping administration is available at `http://localhost:3000/admin/locations`.

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

The application currently includes authentication, roles, locations, and Dejavoo terminal mappings. It does not include the FEED receiver, transaction processing, operational workflows, dashboards, forecasting, notifications, or reports.
