<?php

namespace App\Services;

use App\Helpers\Helper;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AssetMentionService
{
    public function searchResults(string $term): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        $statusRelation = $this->statusRelation();
        $relations = [
            'model',
            'model.manufacturer',
            'location',
            'assignedTo',
        ];

        if ($statusRelation) {
            $relations[] = $statusRelation;
        }

        $settings = Setting::getSettings();

        $assets = Asset::select([
            'assets.id',
            'assets.name',
            'assets.asset_tag',
            'assets.serial',
            'assets.model_id',
            'assets.assigned_to',
            'assets.assigned_type',
            'assets.status_id',
            'assets.location_id',
            'assets.purchase_cost',
        ])
            ->with($relations)
            ->NotArchived()
            ->AssignedSearch($term)
            ->distinct()
            ->orderBy('assets.asset_tag')
            ->limit(12)
            ->get();
        $assignedNames = $this->assignedNames($assets);

        return $assets
            ->map(function (Asset $asset) use ($settings, $assignedNames) {
                return [
                    'id' => (int) $asset->id,
                    'asset_tag' => $asset->asset_tag,
                    'name' => $asset->name,
                    'serial' => $asset->serial,
                    'model' => $asset->model?->name,
                    'model_number' => $asset->model?->model_number,
                    'manufacturer' => $asset->model?->manufacturer?->name,
                    'status' => $this->statusName($asset),
                    'assigned_to' => $assignedNames[$asset->assigned_type][$asset->assigned_to] ?? $this->assignedToName($asset),
                    'location' => $asset->location?->name,
                    'purchase_cost' => $asset->purchase_cost !== null
                        ? trim($settings->default_currency.' '.Helper::formatCurrencyOutput($asset->purchase_cost))
                        : null,
                    'url' => route('hardware.show', $asset),
                ];
            })
            ->values();
    }

    public function syncForLatestActionLog(Request $request, Asset $asset, string $actionType): void
    {
        $mentionedAssetIds = $this->mentionedAssetIds($request, $asset);

        if ($mentionedAssetIds->isEmpty()) {
            return;
        }

        $actionlog = $this->latestActionLog($request, $asset, $actionType);

        if ($actionlog) {
            $actionlog->mentionedAssets()->syncWithoutDetaching($mentionedAssetIds->all());
        }
    }

    public function linkedNote(Actionlog $actionlog): ?string
    {
        if (! $actionlog->note) {
            return null;
        }

        $note = Helper::parseEscapedMarkedownInline($actionlog->note);
        $missingLinks = [];

        foreach ($this->mentionedAssetsForLog($actionlog)->sortByDesc(fn ($asset) => strlen($asset->asset_tag)) as $asset) {
            $link = '<a href="'.e(route('hardware.show', $asset)).'">'.e('@'.$asset->asset_tag).'</a>';
            $replacementCount = 0;
            $mentionTexts = [
                e('@'.$asset->asset_tag),
                e('＠'.$asset->asset_tag),
            ];

            foreach ($mentionTexts as $mentionText) {
                $note = str_replace($mentionText, $link, $note, $count);
                $replacementCount += $count;
            }

            if ($replacementCount === 0) {
                $missingLinks[] = $link;
            }
        }

        if (! empty($missingLinks)) {
            $note .= '<br><span class="text-muted">'.e(trans('general.mentioned_assets')).': '.implode(' ', $missingLinks).'</span>';
        }

        return $note;
    }

    private function latestActionLog(Request $request, Asset $asset, string $actionType): ?Actionlog
    {
        $baseQuery = fn () => Actionlog::where('item_type', Asset::class)
            ->where('item_id', $asset->id)
            ->where('action_type', $actionType);

        if ($request->filled('note')) {
            $actionlog = $baseQuery()
                ->where('note', $request->input('note'))
                ->latest('id')
                ->first();

            if ($actionlog) {
                return $actionlog;
            }
        }

        return $baseQuery()
            ->when(auth()->id(), function ($query) {
                $query->where(function ($query) {
                    $query->where('created_by', auth()->id())
                        ->orWhereNull('created_by');
                });
            })
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest('id')
            ->first()
            ?: $baseQuery()->latest('id')->first();
    }

    private function mentionedAssetIds(Request $request, Asset $asset): Collection
    {
        return collect($request->input('mentioned_assets', []))
            ->merge($this->mentionedAssetIdsFromNote($request->input('note')))
            ->filter()
            ->map(fn ($assetId) => (int) $assetId)
            ->reject(fn ($assetId) => $assetId === $asset->id)
            ->unique()
            ->values();
    }

    private function mentionedAssetIdsFromNote(?string $note): array
    {
        return $this->mentionedAssetsFromNote($note)
            ->pluck('id')
            ->all();
    }

    private function mentionedAssetsForLog(Actionlog $actionlog): Collection
    {
        $actionlog->loadMissing('mentionedAssets');

        return $actionlog->mentionedAssets
            ->merge($this->mentionedAssetsFromNote($actionlog->note))
            ->unique('id')
            ->values();
    }

    private function mentionedAssetsFromNote(?string $note): Collection
    {
        $tokens = $this->mentionTokens($note);

        if ($tokens->isEmpty()) {
            return collect();
        }

        return Asset::whereIn('asset_tag', $tokens->all())
            ->orWhereIn('serial', $tokens->all())
            ->get(['id', 'asset_tag']);
    }

    private function mentionTokens(?string $note): Collection
    {
        if (! $note) {
            return collect();
        }

        preg_match_all('/[@＠]([^\s@＠,，。；;、]+)/u', $note, $matches);

        return collect($matches[1] ?? [])
            ->map(fn ($token) => trim($token, " \t\n\r\0\x0B,，。；;、()（）[]【】"))
            ->filter()
            ->unique()
            ->values();
    }

    private function statusName(Asset $asset): ?string
    {
        $statusRelation = $this->statusRelation();

        if (! $statusRelation) {
            return null;
        }

        return $asset->{$statusRelation}?->name;
    }

    private function assignedToName(Asset $asset): ?string
    {
        return $this->assignedModelName($asset->assignedTo);
    }

    private function assignedModelName($assignedTo): ?string
    {
        if (! $assignedTo) {
            return null;
        }

        if ($assignedTo instanceof User) {
            return collect([
                $assignedTo->display_name,
                trim($assignedTo->first_name.' '.$assignedTo->last_name),
                $assignedTo->username,
            ])->first(fn ($name) => filled($name));
        }

        if ($assignedTo instanceof Asset) {
            return trim($assignedTo->asset_tag.' '.$assignedTo->name);
        }

        return $assignedTo->display_name ?? $assignedTo->name ?? null;
    }

    private function assignedNames(Collection $assets): array
    {
        $names = [];

        $assets
            ->filter(fn (Asset $asset) => $asset->assigned_to && $asset->assigned_type && class_exists($asset->assigned_type))
            ->groupBy('assigned_type')
            ->each(function (Collection $assignedAssets, string $assignedType) use (&$names) {
                $query = $assignedType::query();

                if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($assignedType), true)) {
                    $query->withTrashed();
                }

                $query
                    ->whereIn('id', $assignedAssets->pluck('assigned_to')->unique()->values())
                    ->get()
                    ->each(function ($assignedTo) use (&$names, $assignedType) {
                        $names[$assignedType][$assignedTo->id] = $this->assignedModelName($assignedTo);
                    });
            });

        return $names;
    }

    private function statusRelation(): ?string
    {
        foreach (['status', 'assetstatus'] as $relation) {
            if (method_exists(Asset::class, $relation)) {
                return $relation;
            }
        }

        return null;
    }
}
