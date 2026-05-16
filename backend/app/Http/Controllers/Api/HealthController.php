<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke()
    {
        try {
            DB::connection()->getPdo();
            $users = User::query()->count();

            return response()->json([
                'status' => 'ok',
                'database' => 'connected',
                'users' => $users,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'database' => 'failed',
                'message' => $e->getMessage(),
            ], 503);
        }
    }
}
