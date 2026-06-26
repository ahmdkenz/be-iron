<?php

namespace App\Domain\IAM\Auth\Controllers;

use App\Domain\IAM\Auth\Requests\UpdatePasswordRequest;
use App\Domain\IAM\Auth\Requests\UpdateProfileRequest;
use App\Domain\IAM\User\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles', 'karyawan.perusahaan');

        return $this->successResponse(new UserResource($user));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update($request->only(['username', 'email', 'no_hp']));

        $user->refresh()->load('roles', 'karyawan.perusahaan');

        return $this->successResponse(new UserResource($user), 'Profil berhasil diperbarui');
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return $this->errorResponse(
                'Password saat ini tidak sesuai.',
                422,
                ['current_password' => ['Password saat ini tidak sesuai.']]
            );
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        return $this->successResponse(null, 'Password berhasil diperbarui');
    }
}
