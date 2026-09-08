@extends('layouts/default')

@section('title')
    {{ ($mode ?? 'standard') === 'reminder' ? trans('general.pending_return_reminder') : trans('general.pending_return') }}
    @parent
@stop

@php
    $isReminderMode = ($mode ?? 'standard') === 'reminder';
    $formAction = $formAction ?? route('hardware.pending-return.store', $asset);
    $currentUser = $asset->assignedTo;
    $assetDisplayName = $asset->name ?: optional($asset->model)->name;
    $locationName = optional($asset->location)->name ?: optional($asset->defaultLoc)->name;
    $selectedReplacementId = old('replacement_asset_id', optional($activePendingReturn)->replacement_asset_id);
@endphp

@section('content')
    <style>
        .pending-return-page {
            --pending-blue: #246b8f;
            --pending-cyan: #0ea5b7;
            --pending-orange: #f59e0b;
            --pending-border: #dbe5ec;
            --pending-muted: #687385;
        }

        .pending-return-card {
            border: 0;
            border-radius: 8px;
            box-shadow: 0 14px 34px rgba(36, 107, 143, 0.13);
            overflow: hidden;
        }

        .pending-return-card > .box-header {
            background: linear-gradient(135deg, #246b8f 0%, #0ea5b7 62%, #f59e0b 150%);
            border-bottom: 0;
            color: #fff;
            padding: 15px 18px;
        }

        .pending-return-card > .box-header .box-title {
            color: #fff;
            font-size: 17px;
            font-weight: 600;
        }

        .pending-return-summary {
            background:
                linear-gradient(135deg, rgba(36, 107, 143, 0.08), rgba(14, 165, 183, 0.06) 48%, rgba(245, 158, 11, 0.08)),
                #fff;
            border: 1px solid var(--pending-border);
            border-radius: 8px;
            margin-bottom: 18px;
            overflow: hidden;
            padding: 16px 18px 18px;
            position: relative;
        }

        .pending-return-summary:before {
            background: linear-gradient(180deg, var(--pending-cyan), var(--pending-orange));
            bottom: 0;
            content: '';
            left: 0;
            position: absolute;
            top: 0;
            width: 4px;
        }

        .pending-return-heading {
            align-items: center;
            border-bottom: 1px solid rgba(128, 147, 169, 0.22);
            display: flex;
            gap: 14px;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 14px;
            padding-left: 4px;
        }

        .pending-return-kicker {
            color: var(--pending-muted);
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .pending-return-heading h3 {
            font-size: 20px;
            font-weight: 600;
            line-height: 1.3;
            margin: 0 0 5px;
            overflow-wrap: anywhere;
        }

        .pending-return-heading p {
            color: var(--pending-muted);
            font-size: 13px;
            margin: 0;
            overflow-wrap: anywhere;
        }

        .pending-return-flow {
            align-items: center;
            background: rgba(255, 255, 255, 0.76);
            border: 1px solid rgba(128, 147, 169, 0.24);
            border-radius: 999px;
            display: flex;
            gap: 8px;
            padding: 7px 9px;
            white-space: nowrap;
        }

        .pending-return-badge {
            border-radius: 999px;
            display: inline-block;
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            padding: 7px 10px;
        }

        .pending-return-current {
            background: #eef6fb;
            color: var(--pending-blue);
        }

        .pending-return-target {
            background: #fff3d6;
            color: #9a5a00;
        }

        .pending-return-detail-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .pending-return-detail {
            background: rgba(255, 255, 255, 0.74);
            border: 1px solid rgba(128, 147, 169, 0.16);
            border-radius: 6px;
            min-width: 0;
            padding: 10px 12px;
        }

        .pending-return-detail-label {
            color: var(--pending-muted);
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .pending-return-detail-value {
            color: #222;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .pending-return-form-panel {
            background: #fff;
            border: 1px solid #e3eaf1;
            border-radius: 8px;
            padding: 18px 14px 6px;
        }

        .pending-return-form-panel .form-control {
            border-color: #d8e2ea;
            box-shadow: none;
        }

        .pending-return-form-panel .form-control:focus {
            border-color: var(--pending-cyan);
            box-shadow: 0 0 0 2px rgba(14, 165, 183, 0.12);
        }

        .pending-return-cancel-bar {
            border-top: 1px solid #edf1f5;
            padding: 0 18px 18px;
            text-align: right;
        }

        @media (max-width: 767px) {
            .pending-return-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .pending-return-detail-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 480px) {
            .pending-return-detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="row pending-return-page">
        <div class="col-md-9 col-sm-11 col-xs-12 col-md-offset-1">
            <div class="box box-default pending-return-card">
                <div class="box-header with-border">
                    <h2 class="box-title">
                        {{ $isReminderMode ? trans('general.pending_return_reminder') : trans('general.pending_return') }}: {{ $asset->asset_tag }}
                    </h2>
                </div>

                <form class="form-horizontal" id="pending_return_form" method="post" action="{{ $formAction }}" autocomplete="off">
                    {{ csrf_field() }}

                    <div class="box-body">
                        @if ($activePendingReturn)
                            <div class="alert alert-info">
                                <x-icon type="pending-return" />
                                {{ trans($isReminderMode ? 'admin/hardware/message.pending_return.reminder_active_notice' : 'admin/hardware/message.pending_return.active_notice') }}
                            </div>
                        @endif

                        <div class="pending-return-summary">
                            <div class="pending-return-heading">
                                <div>
                                    <div class="pending-return-kicker">{{ $isReminderMode ? trans('general.pending_return_reminder_confirm') : trans('general.pending_return_confirm') }}</div>
                                    <h3>
                                        <a href="{{ route('hardware.show', $asset) }}">{{ $asset->asset_tag }}</a>
                                    </h3>
                                    <p>{{ $assetDisplayName ?: trans('general.asset') }} · SN: {{ $asset->serial ?: '-' }} · {{ trans('general.location') }}: {{ $locationName ?: '-' }}</p>
                                </div>

                                <div class="pending-return-flow">
                                    <span class="pending-return-badge pending-return-current">{{ optional($asset->assetstatus)->name ?: '-' }}</span>
                                    <span><x-icon type="long-arrow-right" /></span>
                                    <span class="pending-return-badge pending-return-target">{{ $isReminderMode ? trans('general.pending_return_reminder') : trans('general.pending_return') }}</span>
                                </div>
                            </div>

                            <div class="pending-return-detail-grid">
                                <div class="pending-return-detail">
                                    <div class="pending-return-detail-label">{{ trans('general.current_user') }}</div>
                                    <div class="pending-return-detail-value">{!! $currentUser->present()->nameUrl() !!}</div>
                                </div>
                                <div class="pending-return-detail">
                                    <div class="pending-return-detail-label">{{ trans('general.asset_tag') }}</div>
                                    <div class="pending-return-detail-value">{{ $asset->asset_tag ?: '-' }}</div>
                                </div>
                                <div class="pending-return-detail">
                                    <div class="pending-return-detail-label">{{ trans('general.name') }}</div>
                                    <div class="pending-return-detail-value">{{ $asset->name ?: '-' }}</div>
                                </div>
                                <div class="pending-return-detail">
                                    <div class="pending-return-detail-label">{{ trans('admin/hardware/form.model') }}</div>
                                    <div class="pending-return-detail-value">{{ optional($asset->model)->name ?: '-' }}</div>
                                </div>
                                <div class="pending-return-detail">
                                    <div class="pending-return-detail-label">{{ trans('admin/hardware/form.serial') }}</div>
                                    <div class="pending-return-detail-value">{{ $asset->serial ?: '-' }}</div>
                                </div>
                                <div class="pending-return-detail">
                                    <div class="pending-return-detail-label">{{ trans('general.category') }}</div>
                                    <div class="pending-return-detail-value">{{ optional(optional($asset->model)->category)->name ?: '-' }}</div>
                                </div>
                                <div class="pending-return-detail">
                                    <div class="pending-return-detail-label">{{ trans('general.company') }}</div>
                                    <div class="pending-return-detail-value">{{ optional($asset->company)->name ?: '-' }}</div>
                                </div>
                                <div class="pending-return-detail">
                                    <div class="pending-return-detail-label">{{ trans('general.status') }}</div>
                                    <div class="pending-return-detail-value">{{ optional($asset->assetstatus)->name ?: '-' }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="pending-return-form-panel">
                            @if (! $isReminderMode)
                                <div class="form-group {{ $errors->has('replacement_asset_id') ? 'error' : '' }}">
                                    <label for="replacement_asset_id" class="col-md-3 control-label">
                                        {{ trans('general.replacement_asset') }}
                                    </label>
                                    <div class="col-md-8">
                                        <select class="form-control select2" name="replacement_asset_id" id="replacement_asset_id" style="width: 100%">
                                            <option value="">{{ trans('admin/hardware/message.pending_return.no_replacement_option') }}</option>
                                            @foreach ($replacementAssets as $replacementAsset)
                                                @php
                                                    $replacementLabel = implode(' · ', array_filter([
                                                        $replacementAsset->asset_tag,
                                                        optional($replacementAsset->model)->name,
                                                        $replacementAsset->serial ? 'SN: '.$replacementAsset->serial : null,
                                                        optional($replacementAsset->assetstatus)->name,
                                                        optional($replacementAsset->location)->name,
                                                    ]));
                                                @endphp
                                                <option value="{{ $replacementAsset->id }}" @selected((string) $selectedReplacementId === (string) $replacementAsset->id)>
                                                    {{ $replacementLabel }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <p class="help-block">{{ trans('admin/hardware/message.pending_return.replacement_help') }}</p>
                                        {!! $errors->first('replacement_asset_id', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                    </div>
                                </div>
                            @else
                                <div class="form-group">
                                    <label class="col-md-3 control-label">
                                        {{ trans('general.pending_return_reminder') }}
                                    </label>
                                    <div class="col-md-8">
                                        <p class="form-control-static text-muted">
                                            {{ trans('admin/hardware/message.pending_return.reminder_help') }}
                                        </p>
                                    </div>
                                </div>
                            @endif

                            <div class="form-group {{ $errors->has('expected_return_date') ? 'error' : '' }}">
                                <label for="expected_return_date" class="col-md-3 control-label">
                                    {{ trans('general.expected_return_date') }}
                                </label>
                                <div class="col-md-8">
                                    <div class="input-group date col-xl-5 col-lg-5 col-md-7 col-sm-9 col-xs-12" data-provide="datepicker" data-date-format="yyyy-mm-dd" data-autoclose="true">
                                        <input type="text" class="form-control"
                                               placeholder="{{ trans('general.select_date') }}"
                                               name="expected_return_date" id="expected_return_date"
                                               value="{{ old('expected_return_date', optional(optional($activePendingReturn)->expected_return_date)->format('Y-m-d')) }}">
                                        <span class="input-group-addon">
                                            <x-icon type="calendar" />
                                        </span>
                                    </div>
                                    {!! $errors->first('expected_return_date', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                </div>
                            </div>

                            <div class="form-group {{ $errors->has('reason') ? 'error' : '' }}">
                                <label for="reason" class="col-md-3 control-label">
                                    {{ trans('general.pending_return_reason') }}
                                </label>
                                <div class="col-md-8 required">
                                    <input class="form-control" type="text" name="reason" id="reason"
                                           value="{{ old('reason', optional($activePendingReturn)->reason) }}" maxlength="191" required
                                           placeholder="{{ trans($isReminderMode ? 'admin/hardware/message.pending_return.reminder_reason_placeholder' : 'admin/hardware/message.pending_return.reason_placeholder') }}">
                                    {!! $errors->first('reason', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                </div>
                            </div>

                            <div class="form-group {{ $errors->has('note') ? 'error' : '' }}">
                                <label for="note" class="col-md-3 control-label">
                                    {{ trans('general.notes') }}
                                </label>
                                <div class="col-md-8">
                                    <textarea class="col-md-6 form-control" id="note" name="note" rows="4" placeholder="{{ trans($isReminderMode ? 'admin/hardware/message.pending_return.reminder_note_placeholder' : 'admin/hardware/message.pending_return.note_placeholder') }}">{{ old('note', optional($activePendingReturn)->note) }}</textarea>
                                    {!! $errors->first('note', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                </div>
                            </div>
                        </div>
                    </div>

                    <x-redirect_submit_options
                        index_route="hardware.index"
                        :button_label="$activePendingReturn ? ($isReminderMode ? trans('general.update_pending_return_reminder') : trans('general.update_pending_return')) : ($isReminderMode ? trans('general.pending_return_reminder') : trans('general.pending_return'))"
                        :disabled_select="!$asset->model"
                        :options="[
                            'index' => trans('admin/hardware/form.redirect_to_all', ['type' => trans('general.assets')]),
                            'item' => trans('admin/hardware/form.redirect_to_type', ['type' => trans('general.asset')]),
                        ]"
                    />
                </form>

                @if ($activePendingReturn)
                    <div class="pending-return-cancel-bar">
                        <form
                            method="post"
                            action="{{ route('hardware.pending-return.cancel', $asset) }}"
                            class="pending-return-cancel-form"
                            data-cancel-confirm="{{ $activePendingReturn->replacementAsset ? trans('admin/hardware/message.pending_return.cancel_with_replacement_confirm') : trans('admin/hardware/message.pending_return.cancel_confirm') }}"
                        >
                            {{ csrf_field() }}
                            <input type="hidden" name="cancel_reason" value="">
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-ban" aria-hidden="true"></i>
                                {{ $activePendingReturn->replacementAsset ? trans('admin/hardware/message.pending_return.cancel_and_checkin_replacement') : trans('admin/hardware/message.pending_return.cancel_pending_return') }}
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <script nonce="{{ csrf_token() }}">
        document.addEventListener('submit', function (event) {
            var form = event.target.closest('.pending-return-cancel-form');

            if (!form) {
                return;
            }

            var confirmMessage = form.getAttribute('data-cancel-confirm');

            if (confirmMessage && !window.confirm(confirmMessage)) {
                event.preventDefault();
                return;
            }

            var reason = window.prompt('{{ trans('admin/hardware/message.pending_return.cancel_prompt') }}', '');

            if (reason === null) {
                event.preventDefault();
                return;
            }

            var input = form.querySelector('input[name="cancel_reason"]');
            if (input) {
                input.value = reason.trim();
            }
        });
    </script>
@stop
