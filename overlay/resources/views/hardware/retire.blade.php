@extends('layouts/default')

@section('title')
    {{ trans('general.retire') }}
    @parent
@stop

@php
    $purchaseCost = ($asset->purchase_cost !== null && $asset->purchase_cost !== '') ? Helper::formatCurrencyOutput($asset->purchase_cost) : '-';
    $purchaseDate = $asset->purchase_date ? Helper::getFormattedDateObject($asset->purchase_date, 'date', false) : '-';
    $modelName = optional($asset->model)->name ?: '-';
    $modelNumber = optional($asset->model)->model_number ?: '-';
    $categoryName = optional(optional($asset->model)->category)->name ?: '-';
    $manufacturerName = optional(optional($asset->model)->manufacturer)->name ?: '-';
    $supplierName = optional($asset->supplier)->name ?: '-';
    $locationName = optional($asset->location)->name ?: optional($asset->defaultLoc)->name;
@endphp

@section('content')
    <style>
        .input-group {
            padding-left: 0px !important;
        }

        .retire-page {
            --retire-blue: #1f6f96;
            --retire-teal: #1fa6a6;
            --retire-mint: #22c5a5;
            --retire-amber: #f59e0b;
            --retire-border: #dce4ec;
            --retire-text-soft: #667085;
        }

        .retire-card {
            border: 0;
            border-radius: 8px;
            box-shadow: 0 14px 34px rgba(31, 111, 150, 0.13);
            overflow: hidden;
        }

        .retire-card > .box-header {
            background: linear-gradient(135deg, #1f6f96 0%, #1fa6a6 62%, #f59e0b 150%);
            border-bottom: 0;
            color: #fff;
            padding: 15px 18px;
        }

        .retire-card > .box-header .box-title {
            color: #fff;
            font-size: 17px;
            font-weight: 600;
        }

        .retire-card .box-body {
            background: linear-gradient(180deg, #f8fbfd 0, #fff 260px);
            padding: 14px;
        }

        .retire-summary {
            background:
                linear-gradient(135deg, rgba(31, 111, 150, 0.08), rgba(31, 166, 166, 0.06) 44%, rgba(245, 158, 11, 0.08)),
                #fff;
            border: 1px solid var(--retire-border);
            border-radius: 8px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
            margin-bottom: 20px;
            overflow: hidden;
            padding: 16px 18px 18px;
            position: relative;
        }

        .retire-summary:before {
            background: linear-gradient(180deg, var(--retire-teal), var(--retire-amber));
            bottom: 0;
            content: '';
            left: 0;
            position: absolute;
            top: 0;
            width: 4px;
        }

        .retire-summary-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(128, 147, 169, 0.22);
            gap: 14px;
            margin-bottom: 14px;
            padding-bottom: 14px;
            padding-left: 4px;
        }

        .retire-summary-title {
            min-width: 0;
        }

        .retire-kicker {
            color: var(--retire-text-soft);
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .retire-summary-title h3 {
            font-size: 20px;
            font-weight: 600;
            line-height: 1.3;
            margin: 0 0 5px;
            overflow-wrap: anywhere;
        }

        .retire-summary-title p {
            color: var(--retire-text-soft);
            font-size: 13px;
            margin: 0;
            overflow-wrap: anywhere;
        }

        .retire-status-flow {
            align-items: center;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(128, 147, 169, 0.22);
            border-radius: 999px;
            display: flex;
            gap: 8px;
            padding: 7px 9px;
            white-space: nowrap;
            font-weight: 600;
        }

        .retire-status-badge {
            border-radius: 999px;
            display: inline-block;
            font-size: 12px;
            line-height: 1;
            padding: 7px 10px;
        }

        .retire-status-current {
            background: #eef4f7;
            color: var(--retire-blue);
        }

        .retire-status-target {
            background: #fff3d6;
            color: #9a5a00;
        }

        .retire-status-arrow {
            color: #7b8794;
        }

        .retire-detail-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }

        .retire-detail {
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(128, 147, 169, 0.16);
            border-radius: 6px;
            min-width: 0;
            padding: 10px 12px;
        }

        .retire-detail-label {
            color: var(--retire-text-soft);
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .retire-detail-value {
            color: #222;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .retire-form-divider {
            border-top: 1px solid var(--retire-border);
            margin: 18px 0;
        }

        .retire-form-panel {
            background: #fff;
            border: 1px solid #e3eaf1;
            border-radius: 8px;
            padding: 18px 14px 6px;
        }

        .retire-form-panel .form-control {
            border-color: #d8e2ea;
            box-shadow: none;
        }

        .retire-form-panel .form-control:focus {
            border-color: var(--retire-teal);
            box-shadow: 0 0 0 2px rgba(31, 166, 166, 0.12);
        }

        @media (max-width: 767px) {
            .retire-summary-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .retire-detail-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 480px) {
            .retire-detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="row retire-page">
        <div class="col-md-9 col-sm-11 col-xs-12 col-md-offset-1">
            <div class="box box-default retire-card">
                <div class="box-header with-border">
                    <h2 class="box-title">
                        {{ trans('general.retire') }}: {{ $asset->asset_tag }}
                    </h2>
                </div>

                <form class="form-horizontal" method="post" action="{{ route('hardware.retire.store', $asset) }}" autocomplete="off">
                    {{ csrf_field() }}

                    <div class="box-body">
                        <div class="retire-summary">
                            <div class="retire-summary-heading">
                                <div class="retire-summary-title">
                                    <div class="retire-kicker">{{ trans('general.retire') }}确认</div>
                                    <h3>
                                        <a href="{{ route('hardware.show', $asset) }}">{{ $asset->asset_tag }}</a>
                                    </h3>
                                    <p>{{ $asset->name ?: $modelName }} · SN: {{ $asset->serial ?: '-' }} · {{ trans('general.location') }}: {{ $locationName ?: '-' }}</p>
                                </div>

                                <div class="retire-status-flow">
                                    <span class="retire-status-badge retire-status-current">{{ optional($asset->assetstatus)->name ?: '-' }}</span>
                                    <span class="retire-status-arrow"><x-icon type="long-arrow-right" /></span>
                                    <span class="retire-status-badge retire-status-target">{{ $retireStatus->name }}</span>
                                </div>
                            </div>

                            <div class="retire-detail-grid">
                                <div class="retire-detail">
                                    <div class="retire-detail-label">当前状态</div>
                                    <div class="retire-detail-value">{{ optional($asset->assetstatus)->name ?: '-' }}</div>
                                </div>
                                <div class="retire-detail">
                                    <div class="retire-detail-label">{{ trans('general.asset_tag') }}</div>
                                    <div class="retire-detail-value">{{ $asset->asset_tag ?: '-' }}</div>
                                </div>
                                <div class="retire-detail">
                                    <div class="retire-detail-label">{{ trans('general.name') }}</div>
                                    <div class="retire-detail-value">{{ $asset->name ?: '-' }}</div>
                                </div>
                                <div class="retire-detail">
                                    <div class="retire-detail-label">{{ trans('admin/hardware/form.model') }}</div>
                                    <div class="retire-detail-value">{{ $modelName }}</div>
                                </div>
                                <div class="retire-detail">
                                    <div class="retire-detail-label">{{ trans('admin/hardware/form.serial') }}</div>
                                    <div class="retire-detail-value">{{ $asset->serial ?: '-' }}</div>
                                </div>
                                <div class="retire-detail">
                                    <div class="retire-detail-label">{{ trans('general.category') }}</div>
                                    <div class="retire-detail-value">{{ $categoryName }}</div>
                                </div>
                                <div class="retire-detail">
                                    <div class="retire-detail-label">{{ trans('general.supplier') }}</div>
                                    <div class="retire-detail-value">{{ $supplierName }}</div>
                                </div>
                                <div class="retire-detail">
                                    <div class="retire-detail-label">{{ trans('general.company') }}</div>
                                    <div class="retire-detail-value">{{ optional($asset->company)->name ?: '-' }}</div>
                                </div>
                                <div class="retire-detail">
                                    <div class="retire-detail-label">{{ trans('general.location') }}</div>
                                    <div class="retire-detail-value">{{ $locationName ?: '-' }}</div>
                                </div>
                                <div class="retire-detail">
                                    <div class="retire-detail-label">{{ trans('general.purchase_cost') }}</div>
                                    <div class="retire-detail-value">{{ $purchaseCost }}</div>
                                </div>
                                <div class="retire-detail">
                                    <div class="retire-detail-label">{{ trans('general.order_number') }}</div>
                                    <div class="retire-detail-value">{{ $asset->order_number ?: '-' }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="retire-form-divider"></div>

                        <div class="retire-form-panel">
                            <div class="form-group{{ $errors->has('retire_at') ? ' has-error' : '' }}">
                                <label for="retire_at" class="col-sm-3 col-xs-12 col-sm-12 control-label">
                                    {{ trans('general.retire_date') }}
                                </label>

                                <div class="col-md-8 col-xs-12 col-sm-12">
                                    <div class="input-group col-xl-5 col-lg-5 col-md-7 col-sm-9 col-xs-12 required">
                                        <div class="input-group date" data-provide="datepicker"
                                             data-date-format="yyyy-mm-dd" data-autoclose="true">
                                            <input type="text" class="form-control"
                                                   placeholder="{{ trans('general.select_date') }}"
                                                   name="retire_at" id="retire_at"
                                                   value="{{ old('retire_at', date('Y-m-d')) }}">
                                            <span class="input-group-addon">
                                                <x-icon type="calendar" />
                                            </span>
                                        </div>
                                        {!! $errors->first('retire_at', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                    </div>
                                </div>
                            </div>

                            <div class="form-group {{ $errors->has('retire_reason') ? 'error' : '' }}">
                                <label for="retire_reason" class="col-md-3 control-label">
                                    {{ trans('general.retire_reason') }}
                                </label>
                                <div class="col-md-8">
                                    <input class="form-control" type="text" name="retire_reason" id="retire_reason"
                                           value="{{ old('retire_reason') }}" maxlength="191"
                                           placeholder="{{ trans('admin/hardware/message.retire.reason_placeholder') }}">
                                    {!! $errors->first('retire_reason', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                </div>
                            </div>

                            <div class="form-group {{ $errors->has('tracking_number') ? 'error' : '' }}">
                                <label for="tracking_number" class="col-md-3 control-label">
                                    {{ trans('general.sf_tracking_number') }}
                                </label>
                                <div class="col-md-8">
                                    <input class="form-control" type="text" name="tracking_number" id="tracking_number"
                                           value="{{ old('tracking_number') }}" maxlength="191"
                                           placeholder="{{ trans('admin/hardware/message.retire.tracking_placeholder') }}">
                                    {!! $errors->first('tracking_number', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                </div>
                            </div>

                            <div class="form-group {{ $errors->has('note') ? 'error' : '' }}">
                                <label for="note" class="col-md-3 control-label">
                                    {{ trans('general.notes') }}
                                </label>
                                <div class="col-md-8">
                                    <textarea class="col-md-6 form-control" id="note" name="note" rows="4" @required($snipeSettings->require_checkinout_notes) placeholder="{{ trans('admin/hardware/message.retire.note_placeholder') }}">{{ old('note') }}</textarea>
                                    {!! $errors->first('note', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                </div>
                            </div>
                        </div>
                    </div>

                    <x-redirect_submit_options
                        index_route="hardware.index"
                        :button_label="trans('general.retire')"
                        :disabled_select="!$asset->model"
                        :options="[
                            'index' => trans('admin/hardware/form.redirect_to_all', ['type' => trans('general.assets')]),
                            'item' => trans('admin/hardware/form.redirect_to_type', ['type' => trans('general.asset')]),
                        ]"
                    />
                </form>
            </div>
        </div>
    </div>
@stop
