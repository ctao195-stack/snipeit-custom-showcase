<?php

namespace App\Services;

use App\Models\AssetTagSequence;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AssetTagGenerator
{
    public function preview(string $ownershipType, string $deviceType): string
    {
        $sequence = AssetTagSequence::where([
            'ownership_type' => $ownershipType,
            'device_type' => $deviceType,
        ])->first();

        return $this->format($ownershipType, $deviceType, $sequence?->next_number ?? 1);
    }

    public function next(string $ownershipType, string $deviceType): string
    {
        $this->rule($ownershipType, $deviceType);

        return DB::transaction(function () use ($ownershipType, $deviceType) {
            $sequence = AssetTagSequence::where([
                'ownership_type' => $ownershipType,
                'device_type' => $deviceType,
            ])->lockForUpdate()->firstOrFail();

            $tag = $this->format($ownershipType, $deviceType, $sequence->next_number);
            $sequence->increment('next_number');

            return $tag;
        }, 3);
    }

    public function ownershipOptions(): array
    {
        return collect(config('asset_tag_rules.ownership_types'))
            ->mapWithKeys(fn (array $rule, string $key) => [$key => $rule['label']])
            ->all();
    }

    public function deviceOptions(): array
    {
        return collect(config('asset_tag_rules.device_types'))
            ->mapWithKeys(fn (array $rule, string $key) => [$key => $rule['label']])
            ->all();
    }

    private function format(string $ownershipType, string $deviceType, int $number): string
    {
        $rule = $this->rule($ownershipType, $deviceType);
        $serial = str_pad((string) $number, config('asset_tag_rules.serial_width', 5), '0', STR_PAD_LEFT);

        return "{$rule['base_prefix']}-{$rule['device_code']}-{$serial}";
    }

    private function rule(string $ownershipType, string $deviceType): array
    {
        $ownership = config("asset_tag_rules.ownership_types.{$ownershipType}");
        $device = config("asset_tag_rules.device_types.{$deviceType}");

        if (!$ownership || !$device) {
            throw new InvalidArgumentException('Invalid asset tag classification.');
        }

        return [
            'base_prefix' => $ownership['base_prefix'],
            'device_code' => $device['code'],
        ];
    }
}
