@extends('layouts/default')

@section('title')
    {{ trans('general.asset_custodies') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('hardware.index') }}" class="btn btn-primary">{{ trans('general.back') }}</a>
@stop

@push('css')
    <style nonce="{{ csrf_token() }}">
        .custodies-toolbar {
            align-items: center;
            display: flex;
            gap: 10px;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .custodies-hint {
            color: #667085;
            margin: 0;
        }

        .custodies-search {
            display: flex;
            gap: 8px;
            min-width: 360px;
        }

        .custodies-filter {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 14px;
        }

        .custodies-table > tbody > tr > td {
            vertical-align: top;
        }

        .custody-asset-main,
        .custody-user-main {
            font-weight: 700;
        }

        .custody-muted {
            color: #667085;
            font-size: 12px;
            margin-top: 3px;
        }

        .custody-note {
            color: #667085;
            margin-top: 4px;
        }

        .custody-pill {
            background: #eaf8f3;
            border-radius: 999px;
            color: #25744a;
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            padding: 5px 10px;
        }

        .custody-user-status {
            border-radius: 999px;
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            margin-top: 5px;
            padding: 3px 8px;
        }

        .custody-user-status-active {
            background: #edf7ff;
            color: #256f9d;
        }

        .custody-user-status-left {
            background: #fff0f0;
            color: #bb2d3b;
        }

        .custody-user-status-other,
        .custody-user-status-unknown {
            background: #f3f4f6;
            color: #667085;
        }

        .custody-status-dropdown {
            display: block;
            margin-top: 6px;
        }

        .custody-status-dropdown > .btn {
            align-items: center;
            background: #fff;
            border-color: #d8e2ea;
            box-shadow: none;
            color: #344054;
            display: inline-flex;
            gap: 6px;
            line-height: 1.2;
            padding: 4px 8px;
        }

        .custody-status-dropdown > .btn:hover,
        .custody-status-dropdown.open > .btn {
            background: #f8fafc;
            border-color: #9db9cc;
        }

        .custody-status-dropdown .dropdown-menu {
            border-color: #d8e2ea;
            box-shadow: 0 8px 18px rgba(16, 24, 40, .14);
            min-width: 132px;
            padding: 5px 0;
        }

        .custody-status-menu-form {
            margin: 0;
        }

        .custody-status-menu-form button {
            background: transparent;
            border: 0;
            color: #344054;
            display: block;
            font-size: 12px;
            padding: 7px 12px;
            text-align: left;
            width: 100%;
        }

        .custody-status-menu-form button:hover,
        .custody-status-menu-form button:focus {
            background: #eef6fb;
            color: #256f9d;
            outline: none;
        }

        .custody-status-menu-form button.is-current {
            color: #256f9d;
            font-weight: 700;
        }

        .custodies-table-wrap {
            overflow: visible;
        }

        @media (max-width: 767px) {
            .custodies-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .custodies-search {
                min-width: 0;
                width: 100%;
            }

            .custodies-table-wrap {
                overflow-x: auto;
            }
        }
    </style>
@endpush

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">
                        <x-icon type="custody" />
                        {{ trans('general.asset_custodies') }}
                    </h2>
                </div>

                <div class="box-body">
                    <div class="custodies-toolbar">
                        <p class="custodies-hint">{{ trans('admin/hardware/message.custody.dashboard_hint') }}</p>

                        <form method="get" action="{{ route('hardware.custodies.index') }}" class="custodies-search">
                            @if ($previousUserStatus)
                                <input type="hidden" name="previous_user_status" value="{{ $previousUserStatus }}">
                            @endif
                            <input type="text"
                                   class="form-control"
                                   name="search"
                                   value="{{ $search }}"
                                   placeholder="{{ trans('admin/hardware/message.custody.list_search_placeholder') }}">
                            @if ($search !== '')
                                <a href="{{ route('hardware.custodies.index') }}" class="btn btn-default">
                                    <x-icon type="x" />
                                </a>
                            @endif
                            <button type="submit" class="btn btn-primary">
                                <x-icon type="search" />
                                {{ trans('general.search') }}
                            </button>
                            <a href="{{ route('hardware.custodies.export', array_filter(['search' => $search, 'previous_user_status' => $previousUserStatus])) }}" class="btn btn-default">
                                <x-icon type="download" />
                                {{ trans('admin/hardware/message.custody.export_ledger') }}
                            </a>
                        </form>
                    </div>

                    <div class="custodies-filter">
                        <a href="{{ route('hardware.custodies.index', array_filter(['search' => $search])) }}"
                           class="btn btn-sm {{ $previousUserStatus ? 'btn-default' : 'btn-primary' }}">
                            {{ trans('general.all') }}
                        </a>
                        @foreach ($previousUserStatusOptions as $statusValue => $statusLabel)
                            <a href="{{ route('hardware.custodies.index', array_filter(['search' => $search, 'previous_user_status' => $statusValue])) }}"
                               class="btn btn-sm {{ $previousUserStatus === $statusValue ? 'btn-primary' : 'btn-default' }}">
                                {{ $statusLabel }}
                            </a>
                        @endforeach
                    </div>

                    <div class="table-responsive custodies-table-wrap">
                        <table class="table table-striped custodies-table">
                            <thead>
                                <tr>
                                    <th>{{ trans('general.asset') }}</th>
                                    <th>{{ trans('general.previous_user') }}</th>
                                    <th>{{ trans('general.custodian') }}</th>
                                    <th>{{ trans('general.custody_location') }}</th>
                                    <th>{{ trans('general.custody_reason') }}</th>
                                    <th>{{ trans('general.created_at') }}</th>
                                    <th class="text-right">{{ trans('general.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($custodies as $custody)
                                    @php
                                        $asset = $custody->asset;
                                        $custodianDepartment = optional(optional($custody->custodian)->department)->name;
                                        $custodianLocation = optional(optional($custody->custodian)->location)->name;
                                    @endphp
                                    <tr>
                                        <td>
                                            @if ($asset)
                                                <div class="custody-asset-main">
                                                    <a href="{{ route('hardware.show', $asset) }}">{{ $asset->asset_tag }}</a>
                                                    <span class="custody-pill">{{ optional($asset->assetstatus)->name ?: trans('general.asset_custody') }}</span>
                                                </div>
                                                <div class="custody-muted">
                                                    {{ $asset->name ?: optional($asset->model)->name ?: trans('general.asset') }}
                                                    · SN: {{ $asset->serial ?: '-' }}
                                                </div>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>
                                            <div class="custody-user-main">
                                                {!! $custody->previousUser ? $custody->previousUser->present()->nameUrl() : '-' !!}
                                            </div>
                                            @if ($custody->previousUser)
                                                <div class="custody-muted">
                                                    {{ optional($custody->previousUser->department)->name ?: '-' }}
                                                </div>
                                            @endif
                                            @if ($asset)
                                                @can('update', $asset)
                                                    <div class="btn-group custody-status-dropdown">
                                                        <button type="button"
                                                                class="btn btn-xs btn-default dropdown-toggle"
                                                                data-toggle="dropdown"
                                                                aria-haspopup="true"
                                                                aria-expanded="false"
                                                                title="{{ trans('general.previous_user_status') }}">
                                                            <span class="custody-user-status custody-user-status-{{ $custody->previous_user_status ?: 'unknown' }}">
                                                                {{ $custody->previousUserStatusLabel() }}
                                                            </span>
                                                            <span class="caret"></span>
                                                        </button>
                                                        <ul class="dropdown-menu" role="menu">
                                                            <li>
                                                                <form class="custody-status-menu-form"
                                                                      method="post"
                                                                      action="{{ route('hardware.custodies.previous-user-status', $custody) }}">
                                                                    {{ csrf_field() }}
                                                                    {{ method_field('PATCH') }}
                                                                    @foreach ($previousUserStatusOptions as $statusValue => $statusLabel)
                                                                        <button type="submit"
                                                                                name="previous_user_status"
                                                                                value="{{ $statusValue }}"
                                                                                class="{{ $custody->previous_user_status === $statusValue ? 'is-current' : '' }}">
                                                                            @if ($custody->previous_user_status === $statusValue)
                                                                                <i class="fas fa-check fa-fw" aria-hidden="true"></i>
                                                                            @else
                                                                                <span class="fa-fw"></span>
                                                                            @endif
                                                                            {{ $statusLabel }}
                                                                        </button>
                                                                    @endforeach
                                                                </form>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                @else
                                                    <span class="custody-user-status custody-user-status-{{ $custody->previous_user_status ?: 'unknown' }}">
                                                        {{ $custody->previousUserStatusLabel() }}
                                                    </span>
                                                @endcan
                                            @else
                                                <span class="custody-user-status custody-user-status-{{ $custody->previous_user_status ?: 'unknown' }}">
                                                    {{ $custody->previousUserStatusLabel() }}
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="custody-user-main">
                                                {!! $custody->custodian ? $custody->custodian->present()->nameUrl() : '-' !!}
                                            </div>
                                            <div class="custody-muted">
                                                {{ $custodianDepartment ?: '-' }}
                                                @if ($custodianLocation)
                                                    · {{ $custodianLocation }}
                                                @endif
                                            </div>
                                        </td>
                                        <td>
                                            @if ($custody->location)
                                                <a href="{{ route('locations.show', $custody->location) }}">{{ $custody->location->name }}</a>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>
                                            <div>{{ $custody->reason ?: '-' }}</div>
                                            @if ($custody->note)
                                                <div class="custody-note">{{ $custody->note }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            {{ $custody->custody_at ? Helper::getFormattedDateObject($custody->custody_at, 'date', false) : '-' }}
                                            <div class="custody-muted">
                                                {{ trans('general.created_by') }}:
                                                {!! $custody->creator ? $custody->creator->present()->nameUrl() : '-' !!}
                                            </div>
                                        </td>
                                        <td class="text-right">
                                            @if ($asset && $asset->availableForCheckout())
                                                @can('checkout', $asset)
                                                    <a href="{{ route('hardware.checkout.create', $asset) }}" class="btn btn-sm btn-primary" title="{{ trans('admin/hardware/message.custody.checkout_now') }}">
                                                        <x-icon type="checkout" />
                                                    </a>
                                                @endcan
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">
                                            {{ trans('admin/hardware/message.custody.list_empty') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $custodies->links() }}
                </div>
            </div>
        </div>
    </div>
@stop
