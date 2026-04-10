<?php

namespace App\Http\Controllers;

use App\Models\ProcessLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProcessLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ProcessLog::query();

            if ($request->filled('process_type')) {
                $query->where('process_type', (string) $request->input('process_type'));
            }

            if ($request->filled('status')) {
                $query->where('status', (string) $request->input('status'));
            }

            $allowedSortFields = ['id', 'created_at', 'started_at', 'finished_at', 'process_type', 'status'];
            $sortBy = (string) $request->input('sort_by', 'created_at');
            $sortBy = in_array($sortBy, $allowedSortFields, true) ? $sortBy : 'created_at';

            $sortDir = strtolower((string) $request->input('sort_dir', 'desc'));
            $sortDir = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

            $perPage = (int) $request->input('per_page', 20);
            $perPage = max(1, min($perPage, 100));

            $logs = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $logs,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching process logs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $log = ProcessLog::findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $log,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Log not found',
            ], 404);
        }
    }
}
