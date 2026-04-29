<?php

namespace App\Domain\IAM\Auth\Controllers;

use App\Domain\IAM\Auth\Requests\LoginRequest;
use App\Domain\IAM\User\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::with('roles', 'karyawan.perusahaan')
            ->where('username', $request->username)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Username atau password salah', 401);
        }

        if (!$user->status) {
            return $this->errorResponse('Akun Anda tidak aktif. Hubungi administrator.', 403);
        }

        // Revoke previous tokens
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user'  => new UserResource($user),
        ], 'Login berhasil');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return $this->successResponse(null, 'Logout berhasil');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles', 'karyawan.perusahaan');
        return $this->successResponse(new UserResource($user));
    }
}
