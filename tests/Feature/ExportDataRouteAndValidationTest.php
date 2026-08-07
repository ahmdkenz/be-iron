<?php

namespace Tests\Feature;

use App\Domain\Finance\ExportData\Requests\ExportDataExcelRequest;
use App\Domain\Finance\ExportData\Services\ExportDataWorkbookService;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Verifikasi routing + aturan validasi Export Data tanpa menyentuh DB (murni
 * introspeksi route table & Validator) — konsisten dengan konvensi project ini
 * yang menghindari RefreshDatabase (lihat RekonsiliasiBankApRouteTest).
 *
 * Aturan ber-`exists:` sengaja tidak diuji di sini karena butuh koneksi DB.
 */
class ExportDataRouteAndValidationTest extends TestCase
{
    private function findRoute(string $method, string $uri): ?RoutingRoute
    {
        foreach (app('router')->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Spatie RoleMiddleware yang ditumpuk berlaku AND, jadi role yang benar-benar
     * diizinkan adalah irisan semua daftar role:... pada route tsb.
     */
    private function effectiveRoles(RoutingRoute $route): array
    {
        $roleLists = collect($route->gatherMiddleware())
            ->filter(fn($m) => is_string($m) && str_starts_with($m, 'role:'))
            ->map(fn($m) => explode('|', substr($m, 5)))
            ->values();

        if ($roleLists->isEmpty()) {
            return [];
        }

        return $roleLists->reduce(
            fn($carry, $roles) => $carry === null ? $roles : array_values(array_intersect($carry, $roles)),
            null
        );
    }

    private function validate(array $payload): \Illuminate\Contracts\Validation\Validator
    {
        $rules = collect((new ExportDataExcelRequest())->rules())
            ->reject(fn(array $rules) => collect($rules)->contains(
                fn($rule) => is_string($rule) && str_starts_with($rule, 'exists:')
            ))
            ->all();

        return Validator::make($payload, $rules);
    }

    public function test_endpoint_export_data_hanya_untuk_admin_manager_supervisor(): void
    {
        $route = $this->findRoute('POST', 'api/v1/finance/export-data/export-excel');

        $this->assertNotNull($route, 'Route POST export-data/export-excel belum terdaftar');

        $roles = $this->effectiveRoles($route);
        foreach (['ADMIN', 'MANAGER', 'SUPERVISOR'] as $expected) {
            $this->assertContains($expected, $roles);
        }
        $this->assertNotContains('AR', $roles);
        $this->assertNotContains('AP', $roles);
    }

    public function test_endpoint_export_rekening_koran_hanya_untuk_admin_manager_supervisor(): void
    {
        $route = $this->findRoute('GET', 'api/v1/finance/rekening-koran/export-excel');

        $this->assertNotNull($route, 'Route GET rekening-koran/export-excel belum terdaftar');

        $roles = $this->effectiveRoles($route);
        foreach (['ADMIN', 'MANAGER', 'SUPERVISOR'] as $expected) {
            $this->assertContains($expected, $roles);
        }
        $this->assertNotContains('AR', $roles);
    }

    public function test_export_rekening_koran_ikut_rate_limit_export(): void
    {
        $route = $this->findRoute('GET', 'api/v1/finance/rekening-koran/export-excel');

        $this->assertContains('throttle:10,1,ar-export', $route->gatherMiddleware());
    }

    public function test_daftar_report_kosong_ditolak(): void
    {
        $this->assertTrue($this->validate(['reports' => []])->fails());
        $this->assertTrue($this->validate([])->fails());
    }

    public function test_report_key_tidak_dikenal_ditolak(): void
    {
        $validator = $this->validate(['reports' => ['aging_report', 'laporan_hantu']]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('reports.1', $validator->errors()->messages());
    }

    public function test_report_key_duplikat_ditolak(): void
    {
        $this->assertTrue($this->validate(['reports' => ['aging_report', 'aging_report']])->fails());
    }

    public function test_rentang_tanggal_terbalik_ditolak(): void
    {
        $validator = $this->validate([
            'reports' => ['riwayat_pembayaran'],
            'filters' => ['tanggal_dari' => '2026-07-31', 'tanggal_sampai' => '2026-07-01'],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('filters.tanggal_sampai', $validator->errors()->messages());
    }

    public function test_payload_lengkap_yang_valid_diterima(): void
    {
        $validator = $this->validate([
            'reports' => array_keys(ExportDataWorkbookService::REPORTS),
            'filters' => [
                'segment'             => 'B2B',
                'tanggal_dari'        => '2026-07-01',
                'tanggal_sampai'      => '2026-07-31',
                'metode_pembayaran'   => 'TRANSFER',
                'as_of_date'          => '2026-07-28',
                'periode_bulan'       => 7,
                'periode_tahun'       => 2026,
                'no_referensi'        => 'TRX-001',
                'status_rekonsiliasi' => 'MATCHED',
                'status'              => 'AKTIF',
                'bank_type'           => 'BCA',
                'dk'                  => 'K',
                'status_posting_1'    => 'MATCHED',
                'status_posting_2'    => 'POSTED',
            ],
        ]);

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_semua_report_key_di_frontend_dikenal_backend(): void
    {
        // Katalog laporan di halaman Export Data harus sama persis dengan
        // whitelist backend — kalau tidak, tombol export akan menghasilkan 422.
        $this->assertSame([
            'aging_report',
            'rekap_klien',
            'mutasi_piutang',
            'rekening_koran',
            'riwayat_pembayaran',
            'rekap_pembayaran',
            'pendapatan_di_muka',
            'jurnal_pic',
            'kinerja_ar',
        ], array_keys(ExportDataWorkbookService::REPORTS));
    }
}
