<?php

namespace App\Http\Controllers;

use App\Models\Condition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConditionController extends Controller
{
    /**
     * Get filtered conditions as JSON.
     * Query parameters:
     * - search: filter by name (case-insensitive)
     * - category: filter by category
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $category = $request->query('category');

        $conditions = Condition::query()
            ->search($search)
            ->byCategory($category)
            ->select('id', 'name', 'category')
            ->orderBy('name')
            ->get();

        return response()->json($conditions);
    }

    /**
     * Store a new condition.
     * Only clinic staff and school nurse can add new conditions.
     */
    public function store(Request $request): JsonResponse
    {
        // Check role authorization
        $activeRole = strtolower(trim((string) $request->session()->get('active_role', '')));
        $allowedRoles = ['school_nurse', 'clinic_staff'];

        if (! in_array($activeRole, $allowedRoles, true)) {
            return response()->json(
                ['message' => 'Unauthorized. Only clinic staff and school nurses can add conditions.'],
                403
            );
        }

        // Validate input
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
        ]);

        // Check for case-insensitive duplicate
        $existing = Condition::whereRaw('LOWER(name) = ?', [strtolower($validated['name'])])
            ->first();

        if ($existing) {
            return response()->json(
                ['message' => 'A condition with this name already exists.', 'id' => $existing->id],
                409
            );
        }

        // Create the new condition
        $condition = Condition::create([
            'name' => $validated['name'],
            'category' => $validated['category'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return response()->json($condition, 201);
    }
}
