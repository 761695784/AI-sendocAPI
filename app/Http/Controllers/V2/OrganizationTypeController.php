<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V2\OrganizationTypeRequest;
use App\Models\OrganizationType;

class OrganizationTypeController extends Controller
{
    public function index()
    {
        return response()->json(
            OrganizationType::orderBy('level')->orderBy('name')->get(['code', 'name', 'level'])
        );
    }

    public function store(OrganizationTypeRequest $request)
    {
        $type = OrganizationType::create($request->validated());

        return response()->json($type->only(['code', 'name', 'level']), 201);
    }

    public function update(OrganizationTypeRequest $request, OrganizationType $organizationType)
    {
        if ($request->has('level') && $request->input('level') !== $organizationType->level
            && $organizationType->organizations()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Le niveau d\'un type déjà utilisé ne peut pas être modifié.',
            ], 409);
        }

        $organizationType->update($request->validated());

        return response()->json($organizationType->only(['code', 'name', 'level']));
    }

    public function destroy(OrganizationType $organizationType)
    {
        if ($organizationType->organizations()->withTrashed()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce type est utilisé par des organisations et ne peut pas être supprimé.',
            ], 409);
        }

        $organizationType->delete();

        return response()->json(['success' => true]);
    }
}
