<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\V2\TerritoryResource;
use App\Models\Territory;
use Illuminate\Http\Request;

class TerritoryController extends Controller
{
    /** Filtres : level (region|department), parent (code). */
    public function index(Request $request)
    {
        $territories = Territory::with('parent')
            ->when($request->query('level'), fn ($q, $level) => $q->where('level', $level))
            ->when($request->query('parent'), fn ($q, $parent) => $q->whereHas('parent', fn ($p) => $p->where('code', $parent)))
            ->orderBy('name')
            ->get();

        return response()->json(TerritoryResource::collection($territories)->resolve());
    }
}
