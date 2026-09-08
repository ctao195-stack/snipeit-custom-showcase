@extends('layouts/default')

@section('title')
    {{ trans('general.asset_custody') }}
    @parent
@stop

@php
    $assetDisplayName = $asset->name ?: optional($asset->model)->name;
    $assetImage = $asset->present()->imageSrc();
    $currentUser = $currentUser ?? $asset->assignedTo;
    $locationName = optional($asset->location)->name ?: optional($asset->defaultLoc)->name;
    $selectedPreviousUserStatus = old('previous_user_status', $defaultPreviousUserStatus ?? \App\Models\AssetCustody::PREVIOUS_USER_STATUS_ACTIVE);
@endphp

@push('css')
    <style nonce="{{ csrf_token() }}">
        .custody-page {
            --custody-blue: #256f9d;
            --custody-cyan: #1aa6b7;
            --custody-green: #62a65f;
            --custody-border: #dbe6ee;
            --custody-soft: #667085;
        }

        .custody-card {
            border: 0;
            border-radius: 8px;
            box-shadow: 0 14px 34px rgba(37, 111, 157, 0.14);
            overflow: hidden;
        }

        .custody-card > .box-header {
            background: linear-gradient(135deg, var(--custody-blue), var(--custody-cyan) 62%, var(--custody-green));
            border-bottom: 0;
            color: #fff;
            padding: 15px 18px;
        }

        .custody-card > .box-header .box-title {
            color: #fff;
            font-size: 17px;
            font-weight: 600;
        }

        .custody-card .box-body {
            background: linear-gradient(180deg, #f7fbfd 0, #fff 260px);
            padding: 14px;
        }

        .custody-summary {
            background:
                linear-gradient(135deg, rgba(37, 111, 157, 0.09), rgba(26, 166, 183, 0.06) 48%, rgba(98, 166, 95, 0.08)),
                #fff;
            border: 1px solid var(--custody-border);
            border-radius: 8px;
            margin-bottom: 18px;
            overflow: hidden;
            padding: 16px 18px 18px;
            position: relative;
        }

        .custody-summary:before {
            background: linear-gradient(180deg, var(--custody-cyan), var(--custody-green));
            bottom: 0;
            content: '';
            left: 0;
            position: absolute;
            top: 0;
            width: 4px;
        }

        .custody-summary-heading {
            align-items: center;
            border-bottom: 1px solid rgba(128, 147, 169, 0.22);
            display: flex;
            gap: 14px;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 14px;
            padding-left: 4px;
        }

        .custody-asset-title {
            align-items: center;
            display: flex;
            gap: 12px;
            min-width: 0;
        }

        .custody-asset-image {
            align-items: center;
            background: #fff;
            border: 1px solid rgba(128, 147, 169, 0.24);
            border-radius: 8px;
            display: flex;
            flex: 0 0 54px;
            height: 54px;
            justify-content: center;
            overflow: hidden;
            width: 54px;
        }

        .custody-asset-image img {
            height: 100%;
            object-fit: cover;
            width: 100%;
        }

        .custody-asset-title h3 {
            font-size: 20px;
            font-weight: 700;
            line-height: 1.3;
            margin: 0 0 5px;
            overflow-wrap: anywhere;
        }

        .custody-asset-title p {
            color: var(--custody-soft);
            font-size: 13px;
            margin: 0;
            overflow-wrap: anywhere;
        }

        .custody-flow {
            align-items: center;
            background: rgba(255, 255, 255, 0.76);
            border: 1px solid rgba(128, 147, 169, 0.22);
            border-radius: 999px;
            display: flex;
            font-weight: 700;
            gap: 8px;
            padding: 7px 9px;
            white-space: nowrap;
        }

        .custody-flow-badge {
            border-radius: 999px;
            display: inline-block;
            font-size: 12px;
            line-height: 1;
            padding: 7px 10px;
        }

        .custody-flow-current {
            background: #eef4f7;
            color: var(--custody-blue);
        }

        .custody-flow-target {
            background: #eaf8f3;
            color: #25744a;
        }

        .custody-detail-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .custody-detail {
            background: rgba(255, 255, 255, 0.78);
            border: 1px solid rgba(128, 147, 169, 0.16);
            border-radius: 6px;
            min-width: 0;
            padding: 10px 12px;
        }

        .custody-detail-label {
            color: var(--custody-soft);
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .custody-detail-value {
            color: #222;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .custody-form-panel {
            background: #fff;
            border: 1px solid #e3eaf1;
            border-radius: 8px;
            padding: 18px 14px 6px;
        }

        .custody-form-panel .form-control {
            border-color: #d8e2ea;
            box-shadow: none;
        }

        .custody-form-panel .form-control:focus {
            border-color: var(--custody-cyan);
            box-shadow: 0 0 0 2px rgba(26, 166, 183, 0.12);
        }

        .custody-status-options .btn {
            font-weight: 700;
            min-width: 72px;
        }

        .custody-status-options .btn.active {
            background: #256f9d;
            border-color: #256f9d;
            color: #fff;
        }

        @media (max-width: 767px) {
            .custody-summary-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .custody-detail-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 480px) {
            .custody-detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endpush

@section('content')
    <div class="row custody-page">
        <div class="col-md-9 col-sm-11 col-xs-12 col-md-offset-1">
            <div class="box box-default custody-card">
                <div class="box-header with-border">
                    <h2 class="box-title">
                        {{ trans('general.asset_custody') }}: {{ $asset->asset_tag }}
                    </h2>
                </div>

                <form class="form-horizontal" method="post" action="{{ route('hardware.custody.store', $asset) }}" autocomplete="off">
                    {{ csrf_field() }}

                    <div class="box-body">
                        <div class="custody-summary">
                            <div class="custody-summary-heading">
                                <div class="custody-asset-title">
                                    <div class="custody-asset-image">
                                        @if ($assetImage)
                                            <img src="{{ $assetImage }}" alt="{{ e($assetDisplayName ?: $asset->asset_tag) }}">
                                        @else
                                            <x-icon type="assets" />
                                        @endif
                                    </div>

                                    <div>
                                        <h3>
                                            <a href="{{ route('hardware.show', $asset) }}">{{ $asset->asset_tag }}</a>
                                        </h3>
                                        <p>
                                            {{ $assetDisplayName ?: trans('general.asset') }} · SN: {{ $asset->serial ?: '-' }} · {{ trans('general.location') }}: {{ $locationName ?: '-' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="custody-flow">
                                    <span class="custody-flow-badge custody-flow-current">{{ optional($asset->assetstatus)->name ?: '-' }}</span>
                                    <span><x-icon type="long-arrow-right" /></span>
                                    <span class="custody-flow-badge custody-flow-target">{{ $custodyStatus->name }}</span>
                                </div>
                            </div>

                            <div class="custody-detail-grid">
                                <div class="custody-detail">
                                    <div class="custody-detail-label">{{ trans('general.previous_user') }}</div>
                                    <div class="custody-detail-value">{!! $currentUser ? $currentUser->present()->nameUrl() : '-' !!}</div>
                                </div>
                                <div class="custody-detail">
                                    <div class="custody-detail-label">{{ trans('general.previous_user_status') }}</div>
                                    <div class="custody-detail-value">
                                        {{ $previousUserStatusOptions[$selectedPreviousUserStatus] ?? trans('general.previous_user_status_unknown') }}
                                    </div>
                                </div>
                                <div class="custody-detail">
                                    <div class="custody-detail-label">{{ trans('general.asset_tag') }}</div>
                                    <div class="custody-detail-value">{{ $asset->asset_tag ?: '-' }}</div>
                                </div>
                                <div class="custody-detail">
                                    <div class="custody-detail-label">{{ trans('admin/hardware/form.model') }}</div>
                                    <div class="custody-detail-value">{{ optional($asset->model)->name ?: '-' }}</div>
                                </div>
                                <div class="custody-detail">
                                    <div class="custody-detail-label">{{ trans('admin/hardware/form.serial') }}</div>
                                    <div class="custody-detail-value">{{ $asset->serial ?: '-' }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="custody-form-panel">
                            <div class="form-group {{ $errors->has('previous_user_status') ? 'error' : '' }}">
                                <label class="col-md-3 control-label">
                                    {{ trans('general.previous_user_status') }}
                                </label>
                                <div class="col-md-8">
                                    <div class="btn-group custody-status-options" data-toggle="buttons">
                                        @foreach ($previousUserStatusOptions as $statusValue => $statusLabel)
                                            <label class="btn btn-default {{ $selectedPreviousUserStatus === $statusValue ? 'active' : '' }}">
                                                <input type="radio"
                                                       name="previous_user_status"
                                                       value="{{ $statusValue }}"
                                                       autocomplete="off"
                                                       {{ $selectedPreviousUserStatus === $statusValue ? 'checked' : '' }}>
                                                {{ $statusLabel }}
                                            </label>
                                        @endforeach
                                    </div>
                                    {!! $errors->first('previous_user_status', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                </div>
                            </div>

                            @include ('partials.forms.edit.user-select', [
                                'translated_name' => trans('general.custodian'),
                                'fieldname' => 'custodian_user_id',
                                'required' => 'true',
                                'hide_new' => 'true',
                            ])

                            @include ('partials.forms.edit.location-select', [
                                'translated_name' => trans('general.custody_location'),
                                'fieldname' => 'location_id',
                                'selected' => old('location_id', $asset->location_id) ? [old('location_id', $asset->location_id)] : [],
                                'item' => null,
                                'hide_new' => 'true',
                                'help_text' => trans('admin/hardware/message.custody.location_help'),
                            ])

                            <div class="form-group {{ $errors->has('custody_at') ? 'error' : '' }}">
                                <label for="custody_at" class="col-md-3 control-label">
                                    {{ trans('general.custody_date') }}
                                </label>
                                <div class="col-md-8">
                                    <x-input.datepicker
                                        name="custody_at"
                                        end_date="0d"
                                        col_size_class="col-md-5"
                                        :value="old('custody_at', date('Y-m-d'))"
                                        required="1"
                                    />
                                    {!! $errors->first('custody_at', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                </div>
                            </div>

                            <div class="form-group {{ $errors->has('reason') ? 'error' : '' }}">
                                <label for="reason" class="col-md-3 control-label">
                                    {{ trans('general.custody_reason') }}
                                </label>
                                <div class="col-md-8">
                                    <input class="form-control" type="text" name="reason" id="reason"
                                           value="{{ old('reason') }}" maxlength="191" required
                                           placeholder="{{ trans('admin/hardware/message.custody.reason_placeholder') }}">
                                    {!! $errors->first('reason', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                </div>
                            </div>

                            <div class="form-group {{ $errors->has('note') ? 'error' : '' }}">
                                <label for="note" class="col-md-3 control-label">
                                    {{ trans('general.notes') }}
                                </label>
                                <div class="col-md-8">
                                    <textarea class="form-control" id="note" name="note" rows="4" placeholder="{{ trans('admin/hardware/message.custody.note_placeholder') }}">{{ old('note') }}</textarea>
                                    {!! $errors->first('note', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="box-footer">
                        <a class="btn btn-link pull-left" href="{{ route('hardware.show', $asset) }}">{{ trans('button.cancel') }}</a>
                        <button type="submit" class="btn btn-primary pull-right">
                            <x-icon type="custody" />
                            {{ trans('general.asset_custody') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@stop
