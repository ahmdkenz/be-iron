<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Tests\TestCase;

/**
 * Verifikasi konfigurasi routing Data Brand (resource CRUD), tanpa menyentuh
 * DB (murni introspeksi route table) — konsisten dengan konvensi project ini
 * yang menghindari RefreshDatabase (lihat MasterImportServiceTest &
 * RekonsiliasiBankApRouteTest).
 */
class MasterBrandRoleAccessTest extends TestCase
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

    public function test_semua_route_resource_brand_dibatasi_admin_manager_supervisor(): void
    {
        foreach ([
            ['GET', 'api/v1/master/brand'],
            ['POST', 'api/v1/master/brand'],
            ['GET', 'api/v1/master/brand/{brand}'],
            ['PUT', 'api/v1/master/brand/{brand}'],
            ['PATCH', 'api/v1/master/brand/{brand}'],
            ['DELETE', 'api/v1/master/brand/{brand}'],
        ] as [$method, $uri]) {
            $route = $this->findRoute($method, $uri);
            $this->assertNotNull($route, "Route {$method} {$uri} tidak ditemukan");

            $roles = $this->effectiveRoles($route);
            $this->assertContains('ADMIN', $roles, "{$method} {$uri} seharusnya mengizinkan ADMIN");
            $this->assertContains('MANAGER', $roles, "{$method} {$uri} seharusnya mengizinkan MANAGER");
            $this->assertContains('SUPERVISOR', $roles, "{$method} {$uri} seharusnya mengizinkan SUPERVISOR");
            $this->assertNotContains('AR', $roles, "{$method} {$uri} seharusnya tidak bisa diakses role AR");
            $this->assertNotContains('AP', $roles, "{$method} {$uri} seharusnya tidak bisa diakses role AP");
        }
    }

    public function test_user_ar_ditolak_403_saat_memanggil_index_brand_langsung(): void
    {
        $response = $this->actingAs($this->makeUserWithRole('AR'))
            ->getJson('/api/v1/master/brand');

        $response->assertForbidden();
    }

    public function test_user_ap_ditolak_403_saat_memanggil_index_brand_langsung(): void
    {
        $response = $this->actingAs($this->makeUserWithRole('AP'))
            ->getJson('/api/v1/master/brand');

        $response->assertForbidden();
    }

    private function makeUserWithRole(string $role): \App\Models\User
    {
        $user = \Mockery::mock(\App\Models\User::class)->makePartial();
        $user->shouldReceive('hasAnyRole')->andReturnUsing(
            fn($needles) => in_array($role, array_map(
                fn($r) => $r instanceof \App\Support\Enums\RoleEnum ? $r->value : $r,
                (array) $needles
            ), true)
        );
        $user->forceFill(['id' => 1, 'username' => 'tester']);

        return $user;
    }
}
