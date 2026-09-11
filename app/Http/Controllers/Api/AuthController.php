<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Session-based authentication for the SPA.
 *
 * The front-end is served by this same application, so Sanctum's stateful mode
 * applies and the session cookie does all the work — no tokens to store in
 * localStorage, no CORS, and CSRF stays switched on.
 */
final class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        return response()->json(['user' => $this->user($request)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Вы вышли из системы.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->user($request)]);
    }

    /** @return array<string, mixed> */
    private function user(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
