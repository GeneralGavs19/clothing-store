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

            $tables = ['products', 'product_variants', 'product_items', 'sales', 'sale_items'];

            return response()->json([
                'status' => 'ok',
                'database' => 'connected',
                'users' => $users,
                'schema' => collect($tables)->mapWithKeys(
                    fn (string $t) => [$t => \Illuminate\Support\Facades\Schema::hasTable($t)]
                ),
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
