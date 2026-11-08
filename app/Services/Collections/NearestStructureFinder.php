<?php

namespace App\Services\Collections;

use App\Models\CollectionRequest;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Territory;
use Illuminate\Support\Collection;

/**
 * Choisit la structure qui ira chercher un document trouvé :
 *   1. la plus proche en distance (GPS), si elle est à moins de gps_max_km ;
 *   2. sinon une structure du même département,
 *   3. sinon de la même région,
 *   4. sinon la plus proche en distance, quelle qu'elle soit.
 * À égalité (pas de GPS), la structure qui a le moins de collectes en cours.
 */
class NearestStructureFinder
{
    /**
     * @param int[] $excludeIds structures ayant déjà refusé
     * @return array{organization: Organization, reason: string, distance_km: ?float}|null
     */
    public function find(?float $lat, ?float $lng, ?int $territoryId, array $excludeIds = []): ?array
    {
        $candidates = $this->candidates($excludeIds);

        if ($candidates->isEmpty()) {
            return null;
        }

        $hasGps = $lat !== null && $lng !== null;
        $withDistance = $hasGps
            ? $candidates->filter(fn ($o) => $o->latitude !== null && $o->longitude !== null)
                ->map(fn ($o) => [$o, self::distanceKm($lat, $lng, $o->latitude, $o->longitude)])
                ->sortBy(fn ($pair) => $pair[1])
                ->values()
            : collect();

        // 1. Proximité GPS
        if ($first = $withDistance->first()) {
            if ($first[1] <= config('doctrack.collection.gps_max_km', 25)) {
                return $this->result($first[0], 'gps', $first[1]);
            }
        }

        // 2-3. Même département, puis même région
        if ($territoryId) {
            $territories = Territory::all(['id', 'level', 'parent_id'])->keyBy('id');
            $target      = $territories->get($territoryId);

            foreach ([Territory::LEVEL_DEPARTMENT => 'department', Territory::LEVEL_REGION => 'region'] as $level => $reason) {
                $targetAtLevel = $this->ancestorAt($territories, $target, $level);
                if (! $targetAtLevel) {
                    continue;
                }
                $sameArea = $candidates->filter(
                    fn ($o) => $this->ancestorAt($territories, $territories->get($o->territory_id), $level)?->id === $targetAtLevel->id
                );
                if ($sameArea->isNotEmpty()) {
                    return $this->pickAmong($sameArea, $reason, $lat, $lng);
                }
            }
        }

        // 4. Plus proche, même lointaine
        if ($first) {
            return $this->result($first[0], 'gps', $first[1]);
        }

        return null;
    }

    /**
     * Structures participantes du même département que le lieu (sinon de la même région), pour les lister.
     *
     * @return array{level: string, organizations: Collection<int, Organization>}
     */
    public function inArea(int $territoryId): array
    {
        $candidates  = $this->candidates([]);
        $territories = Territory::all(['id', 'level', 'parent_id'])->keyBy('id');
        $target      = $territories->get($territoryId);

        foreach ([Territory::LEVEL_DEPARTMENT => 'department', Territory::LEVEL_REGION => 'region'] as $level => $reason) {
            $targetAtLevel = $this->ancestorAt($territories, $target, $level);
            if (! $targetAtLevel) {
                continue;
            }
            $sameArea = $candidates->filter(
                fn ($o) => $this->ancestorAt($territories, $territories->get($o->territory_id), $level)?->id === $targetAtLevel->id
            )->values();
            if ($sameArea->isNotEmpty()) {
                return ['level' => $reason, 'organizations' => $sameArea];
            }
        }

        return ['level' => 'none', 'organizations' => collect()];
    }

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    /** Structures actives qui acceptent de récupérer des documents. */
    private function candidates(array $excludeIds): Collection
    {
        return Organization::query()
            ->where('is_active', true)
            ->where('accepts_collections', true)
            ->whereHas('type', fn ($q) => $q->where('level', OrganizationType::LEVEL_STRUCTURE))
            ->when($excludeIds, fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->get();
    }

    private function pickAmong(Collection $orgs, string $reason, ?float $lat, ?float $lng): array
    {
        if ($lat !== null && $lng !== null) {
            $located = $orgs->filter(fn ($o) => $o->latitude !== null && $o->longitude !== null);
            if ($located->isNotEmpty()) {
                $best = $located->sortBy(fn ($o) => self::distanceKm($lat, $lng, $o->latitude, $o->longitude))->first();

                return $this->result($best, $reason, self::distanceKm($lat, $lng, $best->latitude, $best->longitude));
            }
        }

        // Répartition de la charge : la structure la moins sollicitée.
        $load = CollectionRequest::open()->whereIn('organization_id', $orgs->pluck('id'))
            ->selectRaw('organization_id, count(*) as n')->groupBy('organization_id')->pluck('n', 'organization_id');

        $best = $orgs->sortBy(fn ($o) => [$load[$o->id] ?? 0, $o->id])->first();

        return $this->result($best, $reason, null);
    }

    private function ancestorAt(Collection $territories, ?Territory $t, string $level): ?Territory
    {
        while ($t) {
            if ($t->level === $level) {
                return $t;
            }
            $t = $t->parent_id ? $territories->get($t->parent_id) : null;
        }

        return null;
    }

    private function result(Organization $org, string $reason, ?float $distance): array
    {
        return ['organization' => $org, 'reason' => $reason, 'distance_km' => $distance];
    }
}
