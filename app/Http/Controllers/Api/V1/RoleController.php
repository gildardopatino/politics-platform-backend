<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Get all available roles
     */
    public function index(): JsonResponse
    {
        $roles = Role::all(['id', 'name']);

        return response()->json([
            'data' => $roles
        ]);
    }
}
