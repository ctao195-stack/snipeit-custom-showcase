<div class="form-group {{ $errors->has('mentioned_assets') ? 'error' : '' }}">
    <label class="col-md-3 control-label">
        {{ trans('general.mentioned_assets') }}
    </label>
    <div class="col-md-8">
        @php
            $mentionedAssetIds = collect(old('mentioned_assets', []))
                ->filter()
                ->map(fn ($assetId) => (int) $assetId)
                ->unique()
                ->values();
            $mentionedAssets = \App\Models\Asset::whereIn('id', $mentionedAssetIds)->get()->keyBy('id');
        @endphp
        <div id="mentioned_assets_inputs">
            @foreach($mentionedAssetIds as $mentionedAssetId)
                @if($mentionedAssets->has($mentionedAssetId))
                    <input type="hidden" name="mentioned_assets[]" value="{{ $mentionedAssetId }}" data-mentioned-asset-input="{{ $mentionedAssetId }}">
                @endif
            @endforeach
        </div>
        <div id="mentioned_assets_chips">
            @foreach($mentionedAssetIds as $mentionedAssetId)
                @if($mentionedAssets->has($mentionedAssetId))
                    <span class="asset-mention-chip" data-mentioned-asset-chip="{{ $mentionedAssetId }}">
                        {{ '@'.$mentionedAssets[$mentionedAssetId]->asset_tag }}
                        <button type="button" class="asset-mention-remove" data-remove-mentioned-asset="{{ $mentionedAssetId }}" aria-label="{{ trans('general.delete') }}">&times;</button>
                    </span>
                @endif
            @endforeach
        </div>
        <p class="help-block">{{ trans('general.type_at_to_search_assets') }}</p>
        {!! $errors->first('mentioned_assets', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>
