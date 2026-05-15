<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\ApiPagination;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ActivityLog::query()->with('user:id,name,email,role')->latest();

        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->toString());
        }

        return response()->json(ApiPagination::format($query->paginate($request->integer('per_page', 20))));
    }
}
