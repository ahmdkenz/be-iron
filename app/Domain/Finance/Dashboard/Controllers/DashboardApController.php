<?php

namespace App\Domain\Finance\Dashboard\Controllers;

use App\Domain\Finance\Dashboard\Services\DashboardApService;
use App\Http\Controllers\Controller;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class DashboardApController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DashboardApService $service) {}

    public function summary(): JsonResponse
    {
        abort_unless(RoleHelper::canAccessApDashboard(auth()->user()), 403, 'Dashboard ini hanya untuk AP');

        return $this->successResponse($this->service->getSummary(auth()->user()));
    }
}
