<?php

namespace Tests\Feature\Assets\Ui;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Statuslabel;
use App\Models\User;
use Tests\TestCase;

class StoreAssetsTest extends TestCase
{
    public function testPageRenders()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.create'))
            ->assertOk()
            ->assertSee('按分类自动编号')
            ->assertSee('资产性质')
            ->assertSee('设备类型');
    }

    public function testClassifiedAssetTagsIncrementIndependently()
    {
        $actor = User::factory()->superuser()->create();
        $model = AssetModel::factory()->create();
        $status = Statuslabel::factory()->rtd()->create();

        $this->createClassifiedAsset($actor, $model, $status, 'rental', 'laptop');
        $this->createClassifiedAsset($actor, $model, $status, 'rental', 'laptop');
        $this->createClassifiedAsset($actor, $model, $status, 'rental', 'monitor');
        $this->createClassifiedAsset($actor, $model, $status, 'owned', 'laptop');

        $this->assertDatabaseHas('assets', [
            'asset_tag' => 'RENT-GZ-B-003-00001',
            'asset_ownership_type' => 'rental',
            'asset_device_type' => 'laptop',
        ]);
        $this->assertDatabaseHas('assets', ['asset_tag' => 'RENT-GZ-B-003-00002']);
        $this->assertDatabaseHas('assets', ['asset_tag' => 'RENT-GZ-B-002-00001']);
        $this->assertDatabaseHas('assets', ['asset_tag' => 'OWN-GZ-B-003-00001']);
    }

    public function testManualAssetTagCreationStillWorks()
    {
        $actor = User::factory()->superuser()->create();
        $model = AssetModel::factory()->create();
        $status = Statuslabel::factory()->rtd()->create();

        $this->actingAs($actor)->post(route('hardware.store'), [
            'asset_tag_mode' => 'manual',
            'asset_tags' => [1 => 'MANUAL-TEST-001'],
            'model_id' => $model->id,
            'status_id' => $status->id,
        ]);

        $this->assertDatabaseHas('assets', [
            'asset_tag' => 'MANUAL-TEST-001',
            'asset_ownership_type' => null,
            'asset_device_type' => null,
        ]);
    }

    private function createClassifiedAsset(
        User $actor,
        AssetModel $model,
        Statuslabel $status,
        string $ownershipType,
        string $deviceType
    ): Asset {
        $this->actingAs($actor)->post(route('hardware.store'), [
            'asset_tag_mode' => 'classified',
            'asset_ownership_type' => $ownershipType,
            'asset_device_type' => $deviceType,
            'asset_tags' => [1 => 'PREVIEW'],
            'model_id' => $model->id,
            'status_id' => $status->id,
        ])->assertSessionHasNoErrors();

        return Asset::latest('id')->firstOrFail();
    }
}
