<?php

namespace App\Domain\Finance\Dashboard\Controllers;

use App\Domain\Finance\Dashboard\Services\DashboardService;
use App\Http\Controllers\Controller;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DashboardService $service) {}

    public function picAr(): JsonResponse
    {
        abort_unless(RoleHelper::canAccessArDashboard(auth()->user()), 403, 'Dashboard ini hanya untuk AR');

        return $this->successResponse($this->service->getPicArOverview(auth()->user()));
    }

    public function global(): JsonResponse
    {
        abort_unless(RoleHelper::hasGlobalFinanceAccess(auth()->user()), 403, 'Akses ditolak');

        return $this->successResponse($this->service->getGlobalOverview(auth()->user()));
    }

    public function kpi(): JsonResponse
    {
        $user = auth()->user();

        $data = RoleHelper::hasGlobalFinanceAccess($user)
            ? $this->service->getGlobalKpiMetrics()
            : $this->service->getKpiMetrics($user);

        return $this->successResponse($data);
    }
}
