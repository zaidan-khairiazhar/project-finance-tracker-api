<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserOnboarding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * @group Auth
 *
 * Registrasi, login, logout, dan profil user. Token Sanctum dikirim sebagai `Authorization: Bearer <token>`.
 */
class AuthController extends Controller
{
    /**
     * Daftar akun baru
     *
     * @unauthenticated
     */
    public function register(RegisterRequest $request, UserOnboarding $onboarding): JsonResponse
    {
        $user = DB::transaction(function () use ($request, $onboarding) {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $onboarding->run($user);

            return $user;
        });

        return $this->tokenResponse($user, 201);
    }

    /**
     * Masuk dan dapatkan token
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // Pesan sama untuk email tidak terdaftar dan password salah, supaya email tidak bisa ditebak.
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau kata sandi salah.'],
            ]);
        }

        return $this->tokenResponse($user, 200);
    }

    /**
     * Keluar (mencabut token saat ini)
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * Profil user yang sedang login
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    private function tokenResponse(User $user, int $status): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => (new UserResource($user))->resolve(),
                'token' => $user->createToken('web')->plainTextToken,
            ],
        ], $status);
    }
}
