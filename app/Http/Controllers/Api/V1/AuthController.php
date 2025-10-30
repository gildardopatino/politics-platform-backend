<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Login user and return JWT token
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only(['email', 'password']);

        if (!$token = auth()->attempt($credentials)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        return $this->respondWithToken($token);
    }

    /**
     * Register new user (Super Admin only)
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'tenant_id' => $request->tenant_id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'telefono' => $request->telefono,
            'is_team_leader' => $request->is_team_leader ?? false,
            'reports_to' => $request->reports_to,
        ]);

        if ($request->roles) {
            $user->assignRole($request->roles);
        }

        $token = auth()->login($user);

        return $this->respondWithToken($token, 201);
    }

    /**
     * Get authenticated user
     */
    public function me(): JsonResponse
    {
        $user = auth()->user();
        $user->load(['roles', 'permissions', 'tenant']);
        
        return response()->json([
            'data' => new UserResource($user)
        ]);
    }

    /**
     * Refresh JWT token
     */
    public function refresh(): JsonResponse
    {
        try {
            $newToken = auth()->refresh();
            
            return $this->respondWithToken($newToken);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'refresh_failed',
                'message' => 'Could not refresh token. Please login again.',
                'requires_login' => true
            ], 401);
        }
    }

    /**
     * Logout user (invalidate token)
     */
    public function logout(): JsonResponse
    {
        try {
            auth()->logout();

            return response()->json([
                'message' => 'Successfully logged out'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Logout successful (token already invalid)'
            ]);
        }
    }

    /**
     * Return JWT token response
     */
    protected function respondWithToken(string $token, int $statusCode = 200): JsonResponse
    {
        $ttl = (int) config('jwt.ttl');
        $refreshTtl = (int) config('jwt.refresh_ttl');
        
        $user = auth()->user();
        $user->load(['roles', 'permissions', 'tenant']);
        
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $ttl * 60, // En segundos
            'expires_at' => now()->addMinutes($ttl)->toISOString(),
            'refresh_expires_in' => $refreshTtl * 60, // En segundos
            'refresh_expires_at' => now()->addMinutes($refreshTtl)->toISOString(),
            'user' => new UserResource($user)
        ], $statusCode);
    }
}
