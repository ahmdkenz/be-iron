<?php

namespace App\Domain\IAM\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\VerifyEmailMail;
use App\Models\User;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class EmailVerificationController extends Controller
{
    use ApiResponse;

    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->email) {
            return $this->errorResponse('Akun Anda belum memiliki email.', 422);
        }

        if (!is_null($user->email_verified_at)) {
            return $this->errorResponse('Email Anda sudah terverifikasi.', 422);
        }

        Mail::to($user->email)->send(new VerifyEmailMail($user));

        return $this->successResponse(null, 'Email verifikasi telah dikirim ke ' . $user->email);
    }

    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));

        if (!URL::hasValidSignature($request)) {
            return redirect($frontendUrl . '/profile?email_verified=failed&reason=invalid_link');
        }

        $user = User::findOrFail($id);

        if (sha1($user->email) !== $hash) {
            return redirect($frontendUrl . '/profile?email_verified=failed&reason=invalid_hash');
        }

        if (!is_null($user->email_verified_at)) {
            return redirect($frontendUrl . '/profile?email_verified=already');
        }

        $user->email_verified_at = now();
        $user->save();

        return redirect($frontendUrl . '/profile?email_verified=success');
    }
}
