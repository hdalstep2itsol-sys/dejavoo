<?php

namespace App\Services;

use App\Enums\DriverCommitmentSource;
use App\Enums\LocationRouteType;
use App\Enums\NormalizedTransactionType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\DejavooTerminal;
use App\Models\Location;
use App\Models\LocationPriceHistory;
use App\Models\NormalizedTransaction;
use App\Models\TrailerLoad;
use App\Models\TrailerLoadAdjustment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoDataService
{
    public const SOURCE = 'development_demo';

    public const USER_EMAILS = [
        'owner.admin@example.test',
        'mike.driver@example.test',
        'john.driver@example.test',
        'sarah.driver@example.test',
        'inactive.driver@example.test',
        'warehouse@example.test',
        'warehouse.two@example.test',
    ];

    public const LOCATION_NAMES = [
        'Landfill',
        'Nolensville',
        'Grassland',
        'Rockvale',
        'Smyrna',
        'LeeAnna',
        'Transfer Station',
    ];

    public const DEMO_TERMINALS = [
        ['Landfill', 'DEMO-TPN-001', 'DEMO-TERM-001', true],
        ['Landfill', 'DEMO-TPN-002', 'DEMO-TERM-002', false],
        ['Nolensville', 'DEMO-TPN-003', 'DEMO-TERM-003', true],
        ['Grassland', 'DEMO-TPN-004', 'DEMO-TERM-004', true],
        ['Rockvale', 'DEMO-TPN-005', 'DEMO-TERM-005', true],
        ['Smyrna', 'DEMO-TPN-006', 'DEMO-TERM-006', true],
        ['LeeAnna', 'DEMO-TPN-007', 'DEMO-TERM-007', true],
        ['Transfer Station', 'DEMO-TPN-008', 'DEMO-TERM-008', false],
    ];

    public const DEMO_ADJUSTMENT_REASONS = [
        '[DEMO] One additional mattress found during review.',
        '[DEMO] Two manually documented mattresses.',
        '[DEMO] Partial-unit reconciliation example.',
        '[DEMO] Duplicate unit removed during reconciliation.',
        '[DEMO] Two offline receipts added.',
        '[DEMO] Damaged item excluded from operational count.',
        '[DEMO] Three non-mattress items removed.',
        '[DEMO] Five verified offline units added.',
    ];

    private const LOCATION_PRICES = [
        'Landfill' => '20.00',
        'Nolensville' => '20.00',
        'Grassland' => '20.00',
        'Rockvale' => '25.00',
        'Smyrna' => '25.00',
        'LeeAnna' => '25.00',
        'Transfer Station' => '25.00',
    ];

    private const PRICE_EFFECTIVE_FROM = '2026-01-01 00:00:00.000000';

    public function __construct(private readonly NormalizedTransactionService $transactions) {}

    /**
     * @return array<string, int>
     */
    public function seed(string $password, bool $reset = false): array
    {
        return DB::transaction(function () use ($password, $reset) {
            if ($reset) {
                $this->resetDemoLocations();
            }

            $users = $this->seedUsers($password);
            $locations = $this->seedLocations($users);
            $terminals = $this->seedTerminals($locations);
            $loads = $this->seedLoads($locations, $users);
            $this->seedTransactions($loads, $terminals);
            $this->seedAdjustments($loads, $users['owner.admin@example.test']);

            $locationIds = $locations->pluck('id');
            $loadIds = TrailerLoad::query()->whereIn('location_id', $locationIds)->pluck('id');

            return [
                'demo users' => User::query()->whereIn('email', self::USER_EMAILS)->count(),
                'demo locations' => Location::query()->whereIn('name', self::LOCATION_NAMES)->count(),
                'demo terminals' => DejavooTerminal::query()->whereIn('location_id', $locationIds)->count(),
                'demo trailer loads' => $loadIds->count(),
                'demo transactions' => NormalizedTransaction::query()->where('source', self::SOURCE)->count(),
                'demo adjustments' => TrailerLoadAdjustment::query()
                    ->whereIn('trailer_load_id', $loadIds)
                    ->whereIn('reason', self::DEMO_ADJUSTMENT_REASONS)
                    ->count(),
            ];
        }, 3);
    }

    /**
     * @return array<string, User>
     */
    private function seedUsers(string $password): array
    {
        $definitions = [
            ['Demo Owner', 'owner.admin@example.test', UserRole::OwnerAdmin, true],
            ['Mike Driver', 'mike.driver@example.test', UserRole::Driver, true],
            ['John Driver', 'john.driver@example.test', UserRole::Driver, true],
            ['Sarah Driver', 'sarah.driver@example.test', UserRole::Driver, true],
            ['Inactive Driver', 'inactive.driver@example.test', UserRole::Driver, false],
            ['Wendy Warehouse', 'warehouse@example.test', UserRole::WarehouseStaff, true],
            ['Walter Warehouse', 'warehouse.two@example.test', UserRole::WarehouseStaff, true],
        ];

        $users = [];
        foreach ($definitions as [$name, $email, $role, $active]) {
            $users[$email] = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'role' => $role,
                    'is_active' => $active,
                    'email_verified_at' => now(),
                    'password' => Hash::make($password),
                ],
            );
        }

        return $users;
    }

    /**
     * @param  array<string, User>  $users
     * @return Collection<string, Location>
     */
    private function seedLocations(array $users): Collection
    {
        $definitions = [
            ['Landfill', '20.00', LocationRouteType::Dedicated, 'mike.driver@example.test', true],
            ['Nolensville', '20.00', LocationRouteType::Open, null, true],
            ['Grassland', '20.00', LocationRouteType::Open, null, true],
            ['Rockvale', '25.00', LocationRouteType::Dedicated, 'john.driver@example.test', true],
            ['Smyrna', '25.00', LocationRouteType::Open, null, true],
            ['LeeAnna', '25.00', LocationRouteType::Dedicated, 'sarah.driver@example.test', true],
            ['Transfer Station', '25.00', LocationRouteType::Open, null, false],
        ];

        $locations = collect();
        foreach ($definitions as [$name, $price, $routeType, $driverEmail, $active]) {
            $existingLocation = Location::query()->where('name', $name)->first();
            if ($existingLocation && ! $this->isOwnedDemoLocation($existingLocation, $name)) {
                throw new RuntimeException(
                    "Demo seed refused: location '{$name}' already exists without its expected demo terminal marker.",
                );
            }

            $location = Location::query()->updateOrCreate(
                ['name' => $name],
                [
                    'unit_price' => $price,
                    'haul_threshold' => '70.00',
                    'route_type' => $routeType,
                    'dedicated_driver_id' => $driverEmail ? $users[$driverEmail]->id : null,
                    'is_active' => $active,
                ],
            );

            LocationPriceHistory::query()->firstOrCreate(
                [
                    'location_id' => $location->id,
                    'effective_from' => self::PRICE_EFFECTIVE_FROM,
                ],
                [
                    'unit_price' => $price,
                    'created_by_user_id' => $users['owner.admin@example.test']->id,
                ],
            );
            $locations->put($name, $location);
        }

        return $locations;
    }

    /**
     * @param  Collection<string, Location>  $locations
     * @return Collection<string, DejavooTerminal>
     */
    private function seedTerminals(Collection $locations): Collection
    {
        $terminals = collect();
        foreach (self::DEMO_TERMINALS as [$locationName, $tpn, $termId, $active]) {
            $existingTerminal = DejavooTerminal::query()->where('tpn', $tpn)->first();
            if ($existingTerminal && $existingTerminal->location_id !== $locations[$locationName]->id) {
                throw new RuntimeException(
                    "Demo seed refused: terminal marker '{$tpn}' belongs to another location.",
                );
            }

            $terminal = DejavooTerminal::query()->updateOrCreate(
                ['tpn' => $tpn],
                [
                    'location_id' => $locations[$locationName]->id,
                    'term_id' => $termId,
                    'is_active' => $active,
                ],
            );
            if (! $terminals->has($locationName)) {
                $terminals->put($locationName, $terminal);
            }
        }

        return $terminals;
    }

    /**
     * @param  Collection<string, Location>  $locations
     * @param  array<string, User>  $users
     * @return array<string, TrailerLoad>
     */
    private function seedLoads(Collection $locations, array $users): array
    {
        $definitions = [
            ['landfill-completed', 'Landfill', TrailerLoadStatus::Completed, '2026-06-01 08:00:00', 'mike.driver@example.test', '2026-06-20 12:00:00', '2026-06-21 09:00:00', 'warehouse@example.test', 71],
            ['landfill-pending', 'Landfill', TrailerLoadStatus::PendingWarehouseCount, '2026-07-01 08:00:00', 'mike.driver@example.test', '2026-08-14 12:00:00', null, null, null],
            ['landfill-active', 'Landfill', TrailerLoadStatus::Active, '2026-08-15 08:00:00', 'mike.driver@example.test', null, null, null, null],
            ['nolensville-completed', 'Nolensville', TrailerLoadStatus::Completed, '2026-07-01 08:00:00', 'mike.driver@example.test', '2026-07-20 12:00:00', '2026-07-20 16:00:00', 'warehouse.two@example.test', 69],
            ['nolensville-active', 'Nolensville', TrailerLoadStatus::Active, '2026-08-20 08:00:00', 'mike.driver@example.test', null, null, null, null],
            ['grassland-completed', 'Grassland', TrailerLoadStatus::Completed, '2026-07-05 08:00:00', 'john.driver@example.test', '2026-07-25 12:00:00', '2026-07-25 16:00:00', 'warehouse@example.test', 74],
            ['grassland-active', 'Grassland', TrailerLoadStatus::Active, '2026-08-25 08:00:00', null, null, null, null, null],
            ['rockvale-pending', 'Rockvale', TrailerLoadStatus::PendingWarehouseCount, '2026-07-15 08:00:00', 'john.driver@example.test', '2026-08-17 12:00:00', null, null, null],
            ['rockvale-active', 'Rockvale', TrailerLoadStatus::Active, '2026-08-18 08:00:00', 'john.driver@example.test', null, null, null, null],
            ['smyrna-completed', 'Smyrna', TrailerLoadStatus::Completed, '2026-07-10 08:00:00', 'sarah.driver@example.test', '2026-08-01 12:00:00', '2026-08-01 16:00:00', 'warehouse.two@example.test', 66],
            ['smyrna-active', 'Smyrna', TrailerLoadStatus::Active, '2026-08-22 08:00:00', 'sarah.driver@example.test', null, null, null, null],
            ['leeanna-completed', 'LeeAnna', TrailerLoadStatus::Completed, '2026-07-10 09:00:00', 'sarah.driver@example.test', '2026-08-15 12:00:00', '2026-08-15 16:00:00', 'warehouse@example.test', 77],
            ['leeanna-active', 'LeeAnna', TrailerLoadStatus::Active, '2026-08-16 08:00:00', 'sarah.driver@example.test', null, null, null, null],
        ];

        $loads = [];
        $owner = $users['owner.admin@example.test'];
        foreach ($definitions as [$key, $locationName, $status, $startedAt, $driverEmail, $swappedAt, $confirmedAt, $warehouseEmail, $actualCount]) {
            $location = $locations[$locationName];
            $driver = $driverEmail ? $users[$driverEmail] : null;
            $attributes = [
                'status' => $status,
                'created_by_user_id' => $owner->id,
                'committed_driver_id' => $driver?->id,
                'commitment_source' => $driver
                    ? ($location->route_type === LocationRouteType::Dedicated
                        ? DriverCommitmentSource::Dedicated
                        : DriverCommitmentSource::OpenClaim)
                    : null,
                'committed_at' => $driver ? $startedAt : null,
                'committed_by_user_id' => $driver ? $owner->id : null,
                'swapped_at' => $swappedAt,
                'swapped_by_user_id' => $swappedAt ? $driver?->id : null,
                'warehouse_actual_count' => $actualCount,
                'warehouse_notes' => $confirmedAt ? '[DEMO] Count confirmed during demo setup.' : null,
                'warehouse_confirmed_at' => $confirmedAt,
                'warehouse_confirmed_by_user_id' => $warehouseEmail ? $users[$warehouseEmail]->id : null,
            ];

            $loads[$key] = TrailerLoad::query()->firstOrCreate(
                ['location_id' => $location->id, 'started_at' => $startedAt],
                $attributes,
            );
        }

        return $loads;
    }

    /**
     * @param  array<string, TrailerLoad>  $loads
     * @param  Collection<string, DejavooTerminal>  $terminals
     */
    private function seedTransactions(array $loads, Collection $terminals): void
    {
        $definitions = [
            ['landfill-completed', NormalizedTransactionType::Sale, '1440.00'],
            ['landfill-pending', NormalizedTransactionType::Sale, '1320.00'],
            ['landfill-active', NormalizedTransactionType::Sale, '1400.00'],
            ['landfill-active', NormalizedTransactionType::Sale, '10.00'],
            ['nolensville-completed', NormalizedTransactionType::Sale, '1400.00'],
            ['nolensville-completed', NormalizedTransactionType::Refund, '10.00'],
            ['nolensville-active', NormalizedTransactionType::Sale, '1200.00'],
            ['nolensville-active', NormalizedTransactionType::Sale, '10.00'],
            ['nolensville-active', NormalizedTransactionType::Refund, '20.00'],
            ['grassland-completed', NormalizedTransactionType::Sale, '1500.00'],
            ['grassland-completed', NormalizedTransactionType::Void, '20.00'],
            ['grassland-active', NormalizedTransactionType::Sale, '1420.00'],
            ['grassland-active', NormalizedTransactionType::Void, '20.00'],
            ['rockvale-pending', NormalizedTransactionType::Sale, '1875.00'],
            ['rockvale-active', NormalizedTransactionType::Sale, '1000.00'],
            ['smyrna-completed', NormalizedTransactionType::Sale, '1500.00'],
            ['smyrna-active', NormalizedTransactionType::Sale, '1775.00'],
            ['leeanna-completed', NormalizedTransactionType::Sale, '2000.00'],
            ['leeanna-completed', NormalizedTransactionType::Refund, '50.00'],
            ['leeanna-active', NormalizedTransactionType::Sale, '1250.00'],
        ];

        $sequenceByLoad = [];
        foreach ($definitions as [$loadKey, $type, $amount]) {
            $load = $loads[$loadKey];
            $sequenceByLoad[$loadKey] = ($sequenceByLoad[$loadKey] ?? 0) + 1;
            $sequence = $sequenceByLoad[$loadKey];
            $externalId = sprintf('demo-%s-%02d', $loadKey, $sequence);

            if (NormalizedTransaction::query()
                ->where('source', self::SOURCE)
                ->where('external_transaction_id', $externalId)
                ->exists()) {
                continue;
            }

            $occurredAt = CarbonImmutable::parse($load->started_at)->addHours($sequence);
            $terminal = $terminals[$load->location->name] ?? null;
            $this->transactions->create(
                locationId: $load->location_id,
                transactionType: $type,
                businessAmount: $amount,
                occurredAt: $occurredAt,
                source: self::SOURCE,
                externalTransactionId: $externalId,
                dejavooTerminalId: $terminal?->id,
            );
        }
    }

    /**
     * @param  array<string, TrailerLoad>  $loads
     */
    private function seedAdjustments(array $loads, User $owner): void
    {
        $definitions = [
            ['landfill-completed', '1.00000000', self::DEMO_ADJUSTMENT_REASONS[0]],
            ['landfill-pending', '2.00000000', self::DEMO_ADJUSTMENT_REASONS[1]],
            ['landfill-active', '1.50000000', self::DEMO_ADJUSTMENT_REASONS[2]],
            ['nolensville-completed', '-1.00000000', self::DEMO_ADJUSTMENT_REASONS[3]],
            ['nolensville-active', '2.00000000', self::DEMO_ADJUSTMENT_REASONS[4]],
            ['rockvale-pending', '-1.00000000', self::DEMO_ADJUSTMENT_REASONS[5]],
            ['rockvale-active', '-3.00000000', self::DEMO_ADJUSTMENT_REASONS[6]],
            ['smyrna-completed', '5.00000000', self::DEMO_ADJUSTMENT_REASONS[7]],
        ];

        foreach ($definitions as [$loadKey, $unitDelta, $reason]) {
            TrailerLoadAdjustment::query()->firstOrCreate(
                ['trailer_load_id' => $loads[$loadKey]->id, 'reason' => $reason],
                ['unit_delta' => $unitDelta, 'created_by_user_id' => $owner->id],
            );
        }
    }

    private function resetDemoLocations(): void
    {
        $locations = Location::query()
            ->whereIn('name', self::LOCATION_NAMES)
            ->whereHas('terminals', fn ($query) => $query->whereIn(
                'tpn',
                array_column(self::DEMO_TERMINALS, 1),
            ))
            ->get();
        if ($locations->isEmpty()) {
            return;
        }

        $locationIds = $locations->pluck('id');
        $loadIds = TrailerLoad::query()->whereIn('location_id', $locationIds)->pluck('id');

        $hasNonDemoTransactions = NormalizedTransaction::query()
            ->whereIn('location_id', $locationIds)
            ->where(fn ($query) => $query
                ->whereNull('source')
                ->orWhere('source', '!=', self::SOURCE))
            ->exists();
        $hasNonDemoAdjustments = TrailerLoadAdjustment::query()
            ->whereIn('trailer_load_id', $loadIds)
            ->whereNotIn('reason', self::DEMO_ADJUSTMENT_REASONS)
            ->exists();
        $locationNames = $locations->pluck('name', 'id');
        $terminalDefinitions = collect(self::DEMO_TERMINALS)->keyBy(fn (array $definition) => $definition[1]);
        $hasNonDemoTerminals = DejavooTerminal::query()
            ->whereIn('location_id', $locationIds)
            ->get()
            ->contains(function (DejavooTerminal $terminal) use ($locationNames, $terminalDefinitions): bool {
                $definition = $terminalDefinitions->get($terminal->tpn);

                return ! $definition
                    || $definition[0] !== $locationNames->get($terminal->location_id)
                    || $definition[2] !== $terminal->term_id;
            });
        $hasNonDemoPriceHistory = LocationPriceHistory::query()
            ->whereIn('location_id', $locationIds)
            ->get()
            ->contains(function (LocationPriceHistory $history) use ($locationNames): bool {
                $locationName = $locationNames->get($history->location_id);

                return ! isset(self::LOCATION_PRICES[$locationName])
                    || self::LOCATION_PRICES[$locationName] !== $history->unit_price
                    || $history->effective_from->format('Y-m-d H:i:s.u') !== self::PRICE_EFFECTIVE_FROM;
            });

        if ($hasNonDemoTransactions || $hasNonDemoAdjustments || $hasNonDemoTerminals || $hasNonDemoPriceHistory) {
            throw new RuntimeException(
                'Reset refused: a demo location contains non-demo terminal, transaction, adjustment, or price-history data.',
            );
        }

        NormalizedTransaction::query()->whereIn('location_id', $locationIds)->delete();
        TrailerLoadAdjustment::query()->whereIn('trailer_load_id', $loadIds)->delete();
        TrailerLoad::query()->whereIn('location_id', $locationIds)->delete();
        DejavooTerminal::query()->whereIn('location_id', $locationIds)->delete();
        LocationPriceHistory::query()->whereIn('location_id', $locationIds)->delete();
        Location::query()->whereIn('id', $locationIds)->delete();
    }

    private function isOwnedDemoLocation(Location $location, string $name): bool
    {
        $expectedTpns = collect(self::DEMO_TERMINALS)
            ->filter(fn (array $definition) => $definition[0] === $name)
            ->pluck(1);

        return $location->terminals()->whereIn('tpn', $expectedTpns)->exists();
    }
}
