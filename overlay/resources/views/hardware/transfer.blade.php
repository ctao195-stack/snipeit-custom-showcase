@extends('layouts/default')

@section('title')
    {{ trans('general.transfer') }}
    @parent
@stop

@php
    $assetImage = $asset->present()->imageSrc();
    $assetDisplayName = $asset->name ?: optional($asset->model)->name;
    $lastCheckout = $asset->last_checkout ? Helper::getFormattedDateObject($asset->last_checkout, 'datetime', false) : null;
    $expectedCheckin = $asset->expected_checkin ? Helper::getFormattedDateObject($asset->expected_checkin, 'date', false) : null;
    $purchaseDate = $asset->purchase_date ? Helper::getFormattedDateObject($asset->purchase_date, 'date', false) : null;
    $purchaseCost = ($asset->purchase_cost !== null && $asset->purchase_cost !== '') ? Helper::formatCurrencyOutput($asset->purchase_cost) : null;
@endphp

@push('css')
    <style nonce="{{ csrf_token() }}">
        .transfer-asset-summary {
            border: 1px solid #d2d6de;
            background: #f8fafc;
            border-radius: 4px;
            margin-bottom: 18px;
            padding: 10px 12px;
        }

        .transfer-asset-header {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 8px;
        }

        .transfer-asset-image {
            width: 44px;
            height: 44px;
            flex: 0 0 44px;
            border: 1px solid #d2d6de;
            border-radius: 4px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .transfer-asset-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .transfer-asset-image .fa {
            color: #8a94a6;
            font-size: 18px;
        }

        .transfer-asset-heading {
            min-width: 0;
        }

        .transfer-asset-heading h3 {
            font-size: 16px;
            font-weight: 600;
            line-height: 1.3;
            margin: 0 0 3px;
            overflow-wrap: anywhere;
        }

        .transfer-asset-subtitle {
            color: #6b7280;
            font-size: 12px;
            margin: 0;
            overflow-wrap: anywhere;
        }

        .transfer-status-line {
            font-size: 12px;
            margin-top: 3px;
        }

        .transfer-detail-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 6px 12px;
        }

        .transfer-preview-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px 14px;
        }

        .transfer-detail,
        .transfer-preview-item {
            min-width: 0;
            border-top: 1px solid #e5e7eb;
            padding-top: 6px;
        }

        .transfer-detail-label,
        .transfer-preview-label {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 3px;
        }

        .transfer-detail-value,
        .transfer-preview-value {
            color: #222;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .transfer-preview-panel {
            border: 1px solid #d2d6de;
            border-left: 3px solid #3c8dbc;
            border-radius: 4px;
            background: #f9fbfd;
            padding: 12px 14px;
        }

        .transfer-receiver-assets {
            border-top: 1px solid #e5e7eb;
            margin-top: 10px;
            padding-top: 10px;
        }

        .transfer-receiver-assets-title {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 6px;
        }

        .transfer-receiver-assets-list {
            max-height: 220px;
            overflow-y: auto;
        }

        .transfer-receiver-assets-table {
            background: #fff;
            margin-bottom: 0;
        }

        .transfer-receiver-assets-table > thead > tr > th,
        .transfer-receiver-assets-table > tbody > tr > td {
            font-size: 12px;
            padding: 6px 8px;
            vertical-align: top;
        }

        .transfer-mode-options {
            padding-top: 7px;
        }

        .transfer-mode-options label {
            margin-right: 18px;
            font-weight: 600;
        }

        .transfer-swap-column {
            display: none;
            width: 42px;
            text-align: center;
        }

        .swap-mode .transfer-swap-column {
            display: table-cell;
        }

        .transfer-swap-help {
            display: none;
            color: #6b7280;
            font-size: 12px;
            margin: 0 0 6px;
        }

        .swap-mode .transfer-swap-help {
            display: block;
        }

        @media (max-width: 767px) {
            .transfer-asset-header {
                align-items: flex-start;
            }

            .transfer-detail-grid,
            .transfer-preview-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endpush

@section('content')
    <div class="row">
        <div class="col-md-9">
            <div class="box box-default">
                <form class="form-horizontal" method="post" action="{{ route('hardware.transfer.store', $asset) }}" autocomplete="off" id="transfer-form" data-asset-tag="{{ e($asset->asset_tag) }}" data-current-user="{{ e($asset->assignedTo->getFullNameAttribute()) }}">
                    <div class="box-header with-border">
                        <h2 class="box-title">{{ trans('general.transfer') }}: {{ $asset->asset_tag }}</h2>
                    </div>

                    <div class="box-body">
                        {{ csrf_field() }}
                        <input type="hidden" name="swap_asset_id" id="swap_asset_id" value="{{ old('swap_asset_id') }}">

                        <div class="transfer-asset-summary">
                            <div class="transfer-asset-header">
                                <div class="transfer-asset-image">
                                    @if ($assetImage)
                                        <img src="{{ $assetImage }}" alt="{{ e($assetDisplayName ?: $asset->asset_tag) }}">
                                    @else
                                        <x-icon type="assets" />
                                    @endif
                                </div>

                                <div class="transfer-asset-heading">
                                    <h3>
                                        <a href="{{ route('hardware.show', $asset) }}">{{ $asset->asset_tag }}</a>
                                        @if ($asset->assetstatus)
                                            <span class="label label-default">{{ $asset->assetstatus->name }}</span>
                                        @endif
                                    </h3>
                                    <p class="transfer-asset-subtitle">
                                        {{ $assetDisplayName ?: trans('general.asset') }}
                                    </p>
                                    <div class="transfer-status-line">
                                        <span class="text-muted">{{ trans('general.current_user') }}:</span>
                                        {!! $asset->assignedTo->present()->nameUrl() !!}
                                    </div>
                                </div>
                            </div>

                            <div class="transfer-detail-grid">
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('general.asset_tag') }}</div>
                                    <div class="transfer-detail-value">{{ $asset->asset_tag ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('general.name') }}</div>
                                    <div class="transfer-detail-value">{{ $asset->name ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('admin/hardware/form.model') }}</div>
                                    <div class="transfer-detail-value">{{ optional($asset->model)->name ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('general.model_no') }}</div>
                                    <div class="transfer-detail-value">{{ optional($asset->model)->model_number ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('admin/hardware/form.serial') }}</div>
                                    <div class="transfer-detail-value">{{ $asset->serial ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('general.category') }}</div>
                                    <div class="transfer-detail-value">{{ optional(optional($asset->model)->category)->name ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('general.manufacturer') }}</div>
                                    <div class="transfer-detail-value">{{ optional(optional($asset->model)->manufacturer)->name ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('general.company') }}</div>
                                    <div class="transfer-detail-value">{{ optional($asset->company)->name ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('general.purchase_cost') }}</div>
                                    <div class="transfer-detail-value">{{ $purchaseCost ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('general.purchase_date') }}</div>
                                    <div class="transfer-detail-value">{{ $purchaseDate ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('general.order_number') }}</div>
                                    <div class="transfer-detail-value">{{ $asset->order_number ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('general.location') }}</div>
                                    <div class="transfer-detail-value">{{ optional($asset->location)->name ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('admin/hardware/form.default_location') }}</div>
                                    <div class="transfer-detail-value">{{ optional($asset->defaultLoc)->name ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('general.last_checkout') }}</div>
                                    <div class="transfer-detail-value">{{ $lastCheckout ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('general.expected_checkin') }}</div>
                                    <div class="transfer-detail-value">{{ $expectedCheckin ?: '-' }}</div>
                                </div>
                                <div class="transfer-detail">
                                    <div class="transfer-detail-label">{{ trans('general.status') }}</div>
                                    <div class="transfer-detail-value">{{ optional($asset->assetstatus)->name ?: '-' }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group {{ $errors->has('transfer_mode') ? 'error' : '' }}">
                            <label class="col-md-3 control-label">{{ trans('general.transfer_mode') }}</label>
                            <div class="col-md-7">
                                <div class="transfer-mode-options">
                                    <label>
                                        <input type="radio" name="transfer_mode" value="direct" {{ old('transfer_mode', 'direct') === 'direct' ? ' checked="checked"' : '' }}>
                                        {{ trans('general.direct_transfer') }}
                                    </label>
                                    <label>
                                        <input type="radio" name="transfer_mode" value="swap" {{ old('transfer_mode') === 'swap' ? ' checked="checked"' : '' }}>
                                        {{ trans('general.swap_transfer') }}
                                    </label>
                                </div>
                                {!! $errors->first('transfer_mode', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                            </div>
                        </div>


                        @include ('partials.forms.edit.user-select', [
                            'translated_name' => trans('general.transfer_to'),
                            'fieldname' => 'transfer_to',
                            'required' => 'true',
                            'hide_new' => 'true',
                        ])

                        <div class="form-group" id="transfer-user-preview" style="display: none;">
                            <label class="col-md-3 control-label">{{ trans('general.receiver_preview') }}</label>
                            <div class="col-md-7">
                                <div class="transfer-preview-panel">
                                    <div class="transfer-preview-grid">
                                        <div class="transfer-preview-item">
                                            <div class="transfer-preview-label">{{ trans('general.name') }}</div>
                                            <div class="transfer-preview-value" data-preview-field="name">-</div>
                                        </div>
                                        <div class="transfer-preview-item">
                                            <div class="transfer-preview-label">{{ trans('general.department') }}</div>
                                            <div class="transfer-preview-value" data-preview-field="department">-</div>
                                        </div>
                                        <div class="transfer-preview-item">
                                            <div class="transfer-preview-label">{{ trans('general.company') }}</div>
                                            <div class="transfer-preview-value" data-preview-field="company">-</div>
                                        </div>
                                        <div class="transfer-preview-item">
                                            <div class="transfer-preview-label">{{ trans('general.location') }}</div>
                                            <div class="transfer-preview-value" data-preview-field="location">-</div>
                                        </div>
                                    </div>
                                    <div class="transfer-receiver-assets">
                                        <div class="transfer-receiver-assets-title">{{ trans('general.receiver_assets') }}</div>
                                        <p class="transfer-swap-help">{{ trans('general.select_swap_asset') }}</p>
                                        <div class="transfer-receiver-assets-list" id="receiver-assets-list">
                                            <span class="text-muted">-</span>
                                        </div>
                                        {!! $errors->first('swap_asset_id', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group {{ $errors->has('status_id') ? 'error' : '' }}">
                            <label for="status_id" class="col-md-3 control-label">
                                {{ trans('admin/hardware/form.status') }}
                            </label>
                            <div class="col-md-7 required">
                                <x-input.select
                                    name="status_id"
                                    :options="$statusLabel_list"
                                    :selected="old('status_id', $asset->status_id)"
                                    style="width: 100%;"
                                    aria-label="status_id"
                                />
                                {!! $errors->first('status_id', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                            </div>
                        </div>

                        <div class="form-group {{ $errors->has('sync_location') ? 'error' : '' }}">
                            <div class="col-md-7 col-md-offset-3">
                                <label class="form-control-static" for="sync_location">
                                    <input id="sync_location" name="sync_location" type="checkbox" value="1" {{ old('sync_location') ? ' checked="checked"' : '' }}>
                                    {{ trans('general.sync_transfer_location') }}
                                </label>
                                <p class="help-block">{{ trans('general.sync_transfer_location_help') }}</p>
                                {!! $errors->first('sync_location', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                            </div>
                        </div>

                        <div class="form-group {{ $errors->has('note') ? 'error' : '' }}">
                            <label for="note" class="col-md-3 control-label">
                                {{ trans('general.notes') }}
                            </label>

                            <div class="col-md-7 required">
                                <textarea class="form-control" id="note" name="note" required>{{ old('note') }}</textarea>
                                {!! $errors->first('note', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                            </div>
                        </div>
                    </div>

                    <div class="box-footer text-right">
                        <a class="btn btn-default" href="{{ route('hardware.show', $asset) }}">{{ trans('button.cancel') }}</a>
                        <button type="submit" class="btn btn-primary">
                            <x-icon type="transfer" />
                            {{ trans('general.transfer') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@stop

@section('moar_scripts')
    <script nonce="{{ csrf_token() }}">
        $(function () {
            var $form = $('#transfer-form');
            var $userSelect = $('#assigned_user_select');
            var $preview = $('#transfer-user-preview');
            var $receiverAssets = $('#receiver-assets-list');
            var userEndpoint = '{{ route('api.users.show', ['user' => '__USER_ID__']) }}';
            var userAssetsEndpoint = '{{ route('api.users.assetlist', ['user' => '__USER_ID__']) }}';
            var assetEndpoint = '{{ route('hardware.show', ['asset' => '__ASSET_ID__']) }}';
            var selectedUserName = '';
            var selectedSwapAssetTag = '';
            var oldSwapAssetId = '{{ old('swap_asset_id') }}';

            function valueOrDash(value) {
                return (value || value === 0) ? value : '-';
            }

            function escapeHtml(value) {
                return String(valueOrDash(value))
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function setPreview(data) {
                selectedUserName = data.name || '';
                $preview.find('[data-preview-field="name"]').text(valueOrDash(data.name));
                $preview.find('[data-preview-field="department"]').text(valueOrDash(data.department ? data.department.name : null));
                $preview.find('[data-preview-field="company"]').text(valueOrDash(data.company ? data.company.name : null));
                $preview.find('[data-preview-field="location"]').text(valueOrDash(data.location ? data.location.name : null));
                $preview.show();
            }

            function clearPreview() {
                selectedUserName = '';
                selectedSwapAssetTag = '';
                $preview.hide();
                $preview.find('[data-preview-field]').text('-');
                $receiverAssets.html('<span class="text-muted">-</span>');
                $('#swap_asset_id').val('');
            }

            function isSwapMode() {
                return $('input[name="transfer_mode"]:checked').val() === 'swap';
            }

            function syncTransferMode() {
                $preview.toggleClass('swap-mode', isSwapMode());

                if (!isSwapMode()) {
                    $('#swap_asset_id').val('');
                    selectedSwapAssetTag = '';
                    $('input[name="swap_asset_choice"]').prop('checked', false);
                }
            }

            function renderReceiverAssets(data) {
                var rows = data && data.rows ? data.rows : [];

                if (!rows.length) {
                    $receiverAssets.html('<span class="text-muted">{{ trans('general.no_current_assets') }}</span>');
                    return;
                }

                var html = '<table class="table table-condensed table-striped transfer-receiver-assets-table">';
                html += '<thead><tr>';
                html += '<th class="transfer-swap-column">{{ trans('general.swap_asset') }}</th>';
                html += '<th>{{ trans('general.asset_tag') }}</th>';
                html += '<th>{{ trans('admin/hardware/form.model') }}</th>';
                html += '<th>{{ trans('admin/hardware/form.serial') }}</th>';
                html += '<th>{{ trans('general.purchase_cost') }}</th>';
                html += '<th>{{ trans('general.status') }}</th>';
                html += '<th>{{ trans('general.location') }}</th>';
                html += '</tr></thead><tbody>';

                $.each(rows, function (index, item) {
                    var assetUrl = assetEndpoint.replace('__ASSET_ID__', item.id);
                    var status = item.status_label && item.status_label.name ? item.status_label.name : null;
                    var location = item.location && item.location.name ? item.location.name : null;
                    var model = item.model && item.model.name ? item.model.name : null;
                    var cost = item.purchase_cost || null;
                    var checked = oldSwapAssetId && String(item.id) === String(oldSwapAssetId) ? ' checked="checked"' : '';

                    html += '<tr>';
                    html += '<td class="transfer-swap-column"><input type="radio" name="swap_asset_choice" value="' + item.id + '" data-asset-tag="' + escapeHtml(item.asset_tag) + '"' + checked + '></td>';
                    html += '<td><a href="' + assetUrl + '">' + escapeHtml(item.asset_tag) + '</a></td>';
                    html += '<td>' + escapeHtml(model || item.name) + '</td>';
                    html += '<td>' + escapeHtml(item.serial) + '</td>';
                    html += '<td>' + escapeHtml(cost) + '</td>';
                    html += '<td>' + escapeHtml(status) + '</td>';
                    html += '<td>' + escapeHtml(location) + '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';
                $receiverAssets.html(html);
                syncTransferMode();

                if (oldSwapAssetId) {
                    var $checked = $('input[name="swap_asset_choice"]:checked');

                    if ($checked.length) {
                        selectedSwapAssetTag = $checked.data('asset-tag') || '';
                        $('#swap_asset_id').val(oldSwapAssetId);
                    }
                }
            }

            function loadReceiverAssets(userId) {
                if (!userId) {
                    $receiverAssets.html('<span class="text-muted">-</span>');
                    return;
                }

                $receiverAssets.html('<span class="text-muted">{{ trans('general.loading') }}</span>');

                $.ajax({
                    url: userAssetsEndpoint.replace('__USER_ID__', userId),
                    type: 'GET',
                    dataType: 'json',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    }
                }).done(renderReceiverAssets).fail(function () {
                    $receiverAssets.html('<span class="text-muted">-</span>');
                });
            }

            function loadUserPreview(userId) {
                if (!userId) {
                    clearPreview();
                    return;
                }

                $.ajax({
                    url: userEndpoint.replace('__USER_ID__', userId),
                    type: 'GET',
                    dataType: 'json',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    }
                }).done(setPreview).fail(clearPreview);

                loadReceiverAssets(userId);
            }

            $userSelect.on('select2:select change', function () {
                oldSwapAssetId = '';
                $('#swap_asset_id').val('');
                selectedSwapAssetTag = '';
                loadUserPreview($(this).val());
            });

            $(document).on('change', 'input[name="transfer_mode"]', syncTransferMode);

            $(document).on('change', 'input[name="swap_asset_choice"]', function () {
                $('#swap_asset_id').val($(this).val());
                selectedSwapAssetTag = $(this).data('asset-tag') || '';
            });

            if ($userSelect.val()) {
                loadUserPreview($userSelect.val());
            }

            syncTransferMode();

            $form.on('submit', function () {
                var toUser = selectedUserName || $userSelect.find('option:selected').text();
                var message = '';

                if (isSwapMode()) {
                    if (!$('#swap_asset_id').val()) {
                        window.alert('{{ trans('general.select_swap_asset') }}');

                        return false;
                    }

                    message = '{{ trans('admin/hardware/message.transfer.confirm_swap') }}'
                        .replace(':asset', $form.data('asset-tag'))
                        .replace(':from', $form.data('current-user'))
                        .replace(':to', $.trim(toUser))
                        .replace(':swap_asset', selectedSwapAssetTag || $('#swap_asset_id').val());
                } else {
                    message = '{{ trans('admin/hardware/message.transfer.confirm_direct') }}'
                        .replace(':asset', $form.data('asset-tag'))
                        .replace(':from', $form.data('current-user'))
                        .replace(':to', $.trim(toUser));
                }

                return window.confirm(message);
            });
        });
    </script>
@stop
