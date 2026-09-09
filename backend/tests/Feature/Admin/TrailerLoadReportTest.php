<?php

namespace Tests\Feature\Admin;

use App\Enums\DriverCommitmentSource;
use App\Enums\LocationRouteType;
use App\Enums\NormalizedTransactionType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\NormalizedTransaction;
use App\Models\TrailerLoad;
use App\Models\TrailerLoadAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class TrailerLoadReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_correct_report_values_and_null_variance(): void
    {
        [$owner, $driverOne, , $locationA, $locationB, $completed, $active] = $this->fixtures();

        $response = $this->actingAs($owner)->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame('60.25000000', $rows[$completed->id]['calculated_units']);
        $this->assertSame('5.00000000', $rows[$completed->id]['manual_adjustment_units']);
        $this->assertSame('65.25000000', $rows[$completed->id]['operational_units']);
        $this->assertSame(80, $rows[$completed->id]['warehouse_actual_count']);
        $this->assertSame('14.75000000', $rows[$completed->id]['warehouse_variance']);
        $this->assertSame($driverOne->id, $rows[$completed->id]['driver']['id']);
        $this->assertNull($rows[$active->id]['warehouse_actual_count']);
        $this->assertNull($rows[$active->id]['warehouse_variance']);
        $this->assertSame($locationA->name, $rows[$completed->id]['location']['name']);
        $this->assertSame($locationB->name, $rows[$active->id]['location']['name']);
    }

    public function test_only_owner_admin_can_access_report_and_exports(): void
    {
        $driver = $this->user(UserRole::Driver);
        $warehouse = $this->user(UserRole::WarehouseStaff);

        foreach (['/api/admin/reports', '/api/admin/reports/export/csv', '/api/admin/reports/export/xlsx'] as $url) {
            $this->actingAs($driver)->get($url)->assertForbidden();
            $this->actingAs($warehouse)->get($url)->assertForbidden();
        }
    }

    public function test_location_status_date_and_driver_filters_work_individually(): void
    {
        [$owner, $driverOne, , $locationA, , $completed, $active, $pending] = $this->fixtures();

        $this->assertReportIds($owner, "location_id={$locationA->id}", [$pending->id, $completed->id]);
        $this->assertReportIds($owner, 'status=active', [$active->id]);
        $this->assertReportIds($owner, 'start_date=2026-09-05', [$pending->id, $active->id]);
        $this->assertReportIds($owner, 'end_date=2026-09-05', [$active->id, $completed->id]);
        $this->assertReportIds($owner, "driver_id={$driverOne->id}", [$pending->id, $completed->id]);
    }

    public function test_multiple_filters_work_together(): void
    {
        [$owner, $driverOne, , $locationA, , $completed] = $this->fixtures();

        $query = http_build_query([
            'location_id' => $locationA->id,
            'status' => TrailerLoadStatus::Completed->value,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'driver_id' => $driverOne->id,
        ]);

        $this->assertReportIds($owner, $query, [$completed->id]);
    }

    public function test_csv_export_uses_filters_and_excludes_sensitive_data(): void
    {
        [$owner, , $driverTwo, , $locationB] = $this->fixtures();

        $response = $this->actingAs($owner)
            ->get("/api/admin/reports/export/csv?location_id={$locationB->id}")
            ->assertOk()
            ->assertDownload();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Location,"Load Status","Started At"', $csv);
        $this->assertStringContainsString($locationB->name, $csv);
        $this->assertStringContainsString("'=Beta Logistics", $csv);
        $this->assertStringContainsString($driverTwo->name, $csv);
        $this->assertStringNotContainsString('Alpha Logistics', $csv);
        $this->assertSensitiveDataIsAbsent($csv);
    }

    public function test_xlsx_export_is_valid_uses_filters_and_excludes_sensitive_data(): void
    {
        [$owner, , , $locationA, $locationB] = $this->fixtures();

        $response = $this->actingAs($owner)
            ->get("/api/admin/reports/export/xlsx?location_id={$locationA->id}&status=completed")
            ->assertOk()
            ->assertDownload();

        $xlsx = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($xlsx) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertIsString($sheet);
        $this->assertStringContainsString('Trailer', $zip->getFromName('xl/workbook.xml'));
        $this->assertStringContainsString($locationA->name, $sheet);
        $this->assertStringContainsString('65.25000000', $sheet);
        $this->assertStringContainsString('14.75000000', $sheet);
        $this->assertStringNotContainsString($locationB->name, $sheet);
        $this->assertSensitiveDataIsAbsent($sheet);
        $zip->close();
    }

    public function test_empty_report_and_exports_return_headers_cleanly(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $emptyLocation = $this->location('Empty Location', LocationRouteType::Open);

        $this->actingAs($owner)
            ->getJson("/api/admin/reports?location_id={$emptyLocation->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');

        $csvResponse = $this->actingAs($owner)
            ->get("/api/admin/reports/export/csv?location_id={$emptyLocation->id}")
            ->assertOk();
        $lines = preg_split('/\r\n|\r|\n/', trim($csvResponse->streamedContent()));
        $this->assertCount(1, $lines);

        $xlsxResponse = $this->actingAs($owner)
            ->get("/api/admin/reports/export/xlsx?location_id={$emptyLocation->id}")
            ->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($xlsxResponse->baseResponse->getFile()->getPathname()) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertIsString($sheet);
        $this->assertStringContainsString('Location', $sheet);
        $this->assertStringNotContainsString('Empty Location', $sheet);
        $zip->close();
    }

    /**
     * @return array{User, User, User, Location, Location, TrailerLoad, TrailerLoad, TrailerLoad}
     */
    private function fixtures(): array
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driverOne = User::factory()->create([
            'name' => 'Driver One',
            'email' => 'private-driver@example.test',
            'role' => UserRole::Driver,
        ]);
        $driverTwo = User::factory()->create([
            'name' => 'Driver Two',
            'role' => UserRole::Driver,
        ]);
        $locationA = $this->location('Alpha Logistics', LocationRouteType::Open);
        $locationB = $this->location('=Beta Logistics', LocationRouteType::Dedicated, $driverTwo);

        $completed = $this->load($locationA, $owner, $driverOne, TrailerLoadStatus::Completed, '2026-09-01 08:00:00', [
            'swapped_at' => '2026-09-02 09:00:00',
            'swapped_by_user_id' => $driverOne->id,
            'warehouse_actual_count' => 80,
            'warehouse_notes' => 'PRIVATE WAREHOUSE NOTE',
            'warehouse_confirmed_at' => '2026-09-02 10:00:00',
            'warehouse_confirmed_by_user_id' => $owner->id,
        ]);
        $active = $this->load($locationB, $owner, $driverTwo, TrailerLoadStatus::Active, '2026-09-05 08:00:00');
        $pending = $this->load($locationA, $owner, $driverOne, TrailerLoadStatus::PendingWarehouseCount, '2026-09-10 08:00:00', [
            'swapped_at' => '2026-09-11 09:00:00',
            'swapped_by_user_id' => $driverOne->id,
        ]);

        NormalizedTransaction::query()->create([
            'location_id' => $locationA->id,
            'trailer_load_id' => $completed->id,
            'source' => 'test',
            'external_transaction_id' => 'SENSITIVE-PAYMENT-ID',
            'transaction_type' => NormalizedTransactionType::Sale,
            'business_amount' => '1205.00',
            'unit_price_snapshot' => '20.00',
            'unit_delta' => '60.25000000',
            'occurred_at' => '2026-09-01 12:00:00',
        ]);
        TrailerLoadAdjustment::query()->create([
            'trailer_load_id' => $completed->id,
            'unit_delta' => '5.00000000',
            'reason' => 'PRIVATE ADJUSTMENT REASON',
            'created_by_user_id' => $owner->id,
        ]);

        return [$owner, $driverOne, $driverTwo, $locationA, $locationB, $completed, $active, $pending];
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function location(
        string $name,
        LocationRouteType $routeType,
        ?User $dedicatedDriver = null,
    ): Location {
        return Location::query()->create([
            'name' => $name,
            'unit_price' => '20.00',
            'haul_threshold' => '70.00',
            'route_type' => $routeType,
            'dedicated_driver_id' => $dedicatedDriver?->id,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function load(
        Location $location,
        User $owner,
        User $driver,
        TrailerLoadStatus $status,
        string $startedAt,
        array $extra = [],
    ): TrailerLoad {
        return TrailerLoad::query()->create([
            'location_id' => $location->id,
            'status' => $status,
            'started_at' => $startedAt,
            'created_by_user_id' => $owner->id,
            'committed_driver_id' => $driver->id,
            'commitment_source' => $location->route_type === LocationRouteType::Dedicated
                ? DriverCommitmentSource::Dedicated
                : DriverCommitmentSource::OpenClaim,
            'committed_at' => $startedAt,
            'committed_by_user_id' => $owner->id,
            ...$extra,
        ]);
    }

    /**
     * @param  list<int>  $expectedIds
     */
    private function assertReportIds(User $owner, string $query, array $expectedIds): void
    {
        $response = $this->actingAs($owner)
            ->getJson("/api/admin/reports?{$query}")
            ->assertOk();

        $this->assertSame($expectedIds, collect($response->json('data'))->pluck('id')->all());
    }

    private function assertSensitiveDataIsAbsent(string $content): void
    {
        $this->assertStringNotContainsString('private-driver@example.test', $content);
        $this->assertStringNotContainsString('SENSITIVE-PAYMENT-ID', $content);
        $this->assertStringNotContainsString('PRIVATE WAREHOUSE NOTE', $content);
        $this->assertStringNotContainsString('PRIVATE ADJUSTMENT REASON', $content);
        $this->assertStringNotContainsString('password', strtolower($content));
    }
}
