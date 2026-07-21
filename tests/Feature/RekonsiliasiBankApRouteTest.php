<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Tests\TestCase;

/**
 * Verifikasi konfigurasi routing rekonsiliasi-bank untuk fitur Payment Voucher AP,
 * tanpa menyentuh DB (murni introspeksi route table) — konsisten dengan konvensi
 * project ini yang menghindari RefreshDatabase (lihat MasterImportServiceTest).
 */
class RekonsiliasiBankApRouteTest extends TestCase
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
     * Spatie RoleMiddleware yang ditumpuk (mis. middleware grup + middleware
     * spesifik per-route) berlaku AND, bukan OR — user harus lolos SEMUA
     * pengecekan role:... yang menempel di route tsb. Jadi role yang benar-benar
     * diizinkan adalah irisan (intersection) semua daftar role:..., bukan
     * gabungannya.
     */
    private function effectiveRoles(RoutingRoute $route): array
    {
        $roleLists = collect($route->gatherMiddleware())
            ->filter(fn($m) => str_starts_with($m, 'role:'))
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

    public function test_endpoint_tagihan_ap_candidates_terbuka_untuk_role_ap(): void
    {
        $route = $this->findRoute('GET', 'api/v1/finance/rekonsiliasi-bank/detail/{detail}/tagihan-ap-candidates');

        $this->assertNotNull($route, 'Route GET tagihan-ap-candidates belum terdaftar');

        $roles = $this->effectiveRoles($route);
        foreach (['ADMIN', 'MANAGER', 'SUPERVISOR', 'AR', 'AP'] as $expected) {
            $this->assertContains($expected, $roles);
        }
    }

    public function test_endpoint_catat_voucher_ap_terbuka_untuk_role_ap(): void
    {
        $route = $this->findRoute('POST', 'api/v1/finance/rekonsiliasi-bank/detail/{detail}/catat-voucher-ap');

        $this->assertNotNull($route, 'Route POST catat-voucher-ap belum terdaftar');

        $roles = $this->effectiveRoles($route);
        foreach (['ADMIN', 'MANAGER', 'SUPERVISOR', 'AR', 'AP'] as $expected) {
            $this->assertContains($expected, $roles);
        }
    }

    public function test_unmatch_dan_catat_bayar_ar_tetap_terbuka_untuk_ap_karena_satu_grup_prefix(): void
    {
        // Endpoint AR lama sengaja tetap dalam satu grup prefix dengan endpoint AP
        // (role:ADMIN|MANAGER|SUPERVISOR|AR|AP) — otorisasi granular per-baris tetap
        // ditegakkan di controller (mis. authorizeInvoiceOwnership), bukan di sini.
        foreach ([
            ['PATCH', 'api/v1/finance/rekonsiliasi-bank/detail/{detail}/unmatch'],
            ['POST', 'api/v1/finance/rekonsiliasi-bank/detail/{detail}/catat-bayar'],
        ] as [$method, $uri]) {
            $route = $this->findRoute($method, $uri);
            $this->assertNotNull($route, "Route {$method} {$uri} tidak ditemukan");
            $this->assertContains('AP', $this->effectiveRoles($route));
        }
    }

    public function test_upload_dan_delete_statement_tetap_dibatasi_admin_manager_supervisor(): void
    {
        foreach ([
            ['POST', 'api/v1/finance/rekonsiliasi-bank/upload'],
            ['DELETE', 'api/v1/finance/rekonsiliasi-bank/{bankStatement}'],
        ] as [$method, $uri]) {
            $route = $this->findRoute($method, $uri);
            $this->assertNotNull($route, "Route {$method} {$uri} tidak ditemukan");

            $roles = $this->effectiveRoles($route);
            $this->assertContains('ADMIN', $roles);
            $this->assertContains('MANAGER', $roles);
            $this->assertContains('SUPERVISOR', $roles);
            $this->assertNotContains('AR', $roles, "{$uri} seharusnya tidak bisa diakses role AR");
            $this->assertNotContains('AP', $roles, "{$uri} seharusnya tidak bisa diakses role AP");
        }
    }
}
