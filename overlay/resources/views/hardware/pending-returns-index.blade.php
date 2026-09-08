@extends('layouts/default')

@section('title')
    {{ trans('general.pending_returns') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('hardware.index') }}" class="btn btn-default">
        {{ trans('general.back') }}
    </a>
@stop

@section('content')
    <style>
        .pending-return-page {
            --board-blue: #2f80b7;
            --board-red: #b83232;
            --board-orange: #b76b00;
            --board-green: #1f7a61;
            --board-purple: #7151a1;
            --board-border: #dfe7ef;
            --board-muted: #687385;
            --board-soft: #f7fafc;
        }

        .pending-return-overview {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(5, minmax(118px, 1fr));
            margin-bottom: 14px;
        }

        .pending-return-overview-tile {
            background: #fff;
            border: 1px solid var(--board-border);
            border-left: 4px solid #cbd6df;
            border-radius: 8px;
            color: #2f3b4a;
            display: block;
            min-height: 76px;
            padding: 11px 12px;
            text-decoration: none;
            transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease;
        }

        .pending-return-overview-tile:hover,
        .pending-return-overview-tile:focus {
            border-color: #9fbfd8;
            box-shadow: 0 8px 18px rgba(47, 76, 102, .11);
            color: #2f3b4a;
            text-decoration: none;
            transform: translateY(-1px);
        }

        .pending-return-overview-tile.active {
            border-color: #8fb8d8;
            box-shadow: inset 0 0 0 1px rgba(47, 128, 183, .18);
        }

        .pending-return-overview-all {
            border-left-color: var(--board-blue);
        }

        .pending-return-overview-overdue {
            border-left-color: var(--board-red);
        }

        .pending-return-overview-today {
            border-left-color: var(--board-orange);
        }

        .pending-return-overview-week {
            border-left-color: var(--board-green);
        }

        .pending-return-overview-no_date {
            border-left-color: #7f8c8d;
        }

        .pending-return-overview-label {
            color: #647184;
            display: block;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0;
            margin-bottom: 8px;
        }

        .pending-return-overview-count {
            color: #2f3b4a;
            display: block;
            font-size: 27px;
            font-weight: 700;
            line-height: 1;
        }

        .pending-return-overview-hint {
            color: #798696;
            display: block;
            font-size: 12px;
            margin-top: 6px;
        }

        .pending-return-type-tabs {
            align-items: center;
            background: var(--board-soft);
            border: 1px solid var(--board-border);
            border-radius: 8px;
            display: flex;
            gap: 6px;
            justify-content: space-between;
            margin-bottom: 14px;
            padding: 6px;
        }

        .pending-return-type-tab {
            border: 1px solid transparent;
            border-radius: 6px;
            color: #536173;
            flex: 1 1 0;
            min-height: 48px;
            padding: 7px 9px;
            text-decoration: none;
        }

        .pending-return-type-tab:hover,
        .pending-return-type-tab:focus {
            background: #fff;
            border-color: #d2dde7;
            color: #2f3b4a;
            text-decoration: none;
        }

        .pending-return-type-tab.active {
            background: #fff;
            border-color: #8fb8d8;
            box-shadow: 0 3px 10px rgba(47, 76, 102, .09);
            color: #23455f;
        }

        .pending-return-type-main {
            display: block;
            font-size: 13px;
            font-weight: 700;
        }

        .pending-return-type-count {
            color: var(--board-blue);
            font-size: 16px;
            font-weight: 700;
            margin-left: 4px;
        }

        .pending-return-type-hint {
            color: #7b8795;
            display: block;
            font-size: 12px;
            margin-top: 3px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .pending-returns-command-bar {
            align-items: center;
            display: flex;
            gap: 12px;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .pending-returns-hint {
            color: var(--board-muted);
            font-size: 13px;
            font-weight: 600;
        }

        .pending-returns-toolbar {
            align-items: center;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .pending-returns-toolbar .form-control {
            max-width: 360px;
        }

        .pending-return-priority {
            background: #fff;
            border: 1px solid #ecd1d1;
            border-radius: 8px;
            margin-bottom: 14px;
            overflow: hidden;
        }

        .pending-return-priority-header {
            align-items: center;
            background: #fff7f7;
            border-bottom: 1px solid #ecd1d1;
            display: flex;
            justify-content: space-between;
            padding: 10px 12px;
        }

        .pending-return-priority-title {
            color: #8f2525;
            font-weight: 700;
        }

        .pending-return-priority-meta {
            color: #8f6b6b;
            font-size: 12px;
            font-weight: 600;
        }

        .pending-return-priority-list {
            display: grid;
            gap: 0;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .pending-return-priority-item {
            border-right: 1px solid #f0dddd;
            min-width: 0;
            padding: 11px 12px 12px;
        }

        .pending-return-priority-item:last-child {
            border-right: 0;
        }

        .pending-return-priority-asset {
            font-weight: 700;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .pending-return-priority-reason {
            color: #576577;
            font-size: 12px;
            margin-top: 5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .pending-return-table {
            margin-bottom: 0;
        }

        .pending-return-table > thead > tr > th {
            background: #f9fbfd;
            border-bottom: 1px solid #e6edf4;
            color: #425064;
            font-size: 12px;
            white-space: nowrap;
        }

        .pending-return-asset-col {
            width: 32%;
        }

        .pending-return-user-col {
            width: 22%;
        }

        .pending-return-status-col {
            width: 34%;
        }

        .pending-return-actions-col {
            width: 12%;
        }

        .pending-return-table > tbody > tr.pending-return-main-row > td {
            border-top: 1px solid #edf2f6;
            vertical-align: middle;
        }

        .pending-return-table > tbody > tr.pending-return-detail-row > td {
            background: #fbfcfe;
            border-top: 0;
            padding: 0 14px 14px;
        }

        .pending-return-row-overdue > td {
            background: #fff8f8;
        }

        .pending-return-row-today > td {
            background: #fffaf0;
        }

        .pending-return-row-week > td {
            background: #f8fffc;
        }

        .pending-return-asset {
            font-weight: 700;
            margin-bottom: 5px;
        }

        .pending-return-meta {
            color: var(--board-muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .pending-return-meta-line {
            color: #596474;
            font-size: 12px;
            margin-top: 6px;
        }

        .pending-return-person {
            font-weight: 700;
        }

        .pending-return-role {
            background: #edf6fb;
            border-radius: 999px;
            color: #2f6f99;
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
            margin-right: 6px;
            padding: 5px 7px;
            vertical-align: middle;
            white-space: nowrap;
        }

        .pending-return-role-new {
            background: #ecf8f3;
            color: #21836d;
        }

        .pending-return-role-borrowed {
            background: #f3edf8;
            color: var(--board-purple);
        }

        .pending-return-role-reminder {
            background: #fff4d8;
            color: var(--board-orange);
        }

        .pending-return-role-standard {
            background: #edf6fb;
            color: var(--board-blue);
        }

        .pending-return-type-cell {
            min-width: 140px;
        }

        .pending-return-type-title {
            color: #2f3b4a;
            font-weight: 700;
        }

        .pending-return-type-desc {
            color: var(--board-muted);
            font-size: 12px;
            margin-top: 4px;
        }

        .pending-return-reason-summary {
            color: #354052;
            max-width: 360px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .pending-return-status-cell {
            min-width: 250px;
        }

        .pending-return-status-head {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 7px;
        }

        .pending-return-work-summary {
            color: #2f3b4a;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .pending-return-label {
            border-radius: 999px;
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            padding: 6px 9px;
            white-space: nowrap;
        }

        .pending-return-label-muted {
            background: #eef2f5;
            color: #596474;
        }

        .pending-return-label-soon {
            background: #fff3d6;
            color: #925b00;
        }

        .pending-return-label-overdue {
            background: #fde2e2;
            color: #a61b1b;
        }

        .pending-return-label-good {
            background: #e5f6ee;
            color: #176d4d;
        }

        .pending-return-status-text {
            color: var(--board-muted);
            font-size: 12px;
            font-weight: 700;
            margin-top: 6px;
        }

        .pending-return-status-head .pending-return-status-text {
            margin-top: 0;
        }

        .pending-return-actions {
            white-space: nowrap;
        }

        .pending-return-actions .btn {
            margin-left: 4px;
        }

        .pending-return-detail-panel {
            border: 1px solid #e4ebf2;
            border-radius: 8px;
            padding: 12px;
        }

        .pending-return-detail-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .pending-return-detail-block {
            background: #fff;
            border: 1px solid #edf2f6;
            border-radius: 6px;
            min-width: 0;
            padding: 10px;
        }

        .pending-return-detail-label {
            color: #687385;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .pending-return-detail-value {
            color: #2f3b4a;
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        @media (max-width: 991px) {
            .pending-return-overview {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .pending-return-type-tabs,
            .pending-returns-command-bar {
                align-items: stretch;
                flex-direction: column;
            }

            .pending-return-priority-list,
            .pending-return-detail-grid {
                grid-template-columns: 1fr;
            }

            .pending-return-priority-item {
                border-right: 0;
                border-bottom: 1px solid #f0dddd;
            }

            .pending-return-priority-item:last-child {
                border-bottom: 0;
            }
        }

        @media (max-width: 767px) {
            .pending-return-overview {
                grid-template-columns: 1fr;
            }

            .pending-returns-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .pending-returns-toolbar .form-control,
            .pending-returns-toolbar .btn {
                max-width: none;
                width: 100%;
            }
        }
    </style>

    <div class="row pending-return-page">
        <div class="col-md-12">
            <div class="box">
                <div class="box-header with-border">
                    <h2 class="box-title">
                        <x-icon type="pending-return" />
                        {{ trans('general.pending_returns') }}
                    </h2>
                </div>

                <div class="box-body">
                    @php
                        $visibleRows = collect($pendingReturns->items());

                        $pendingReturnFilters = [
                            'all' => [
                                'label' => trans('admin/hardware/message.pending_return.filter_all'),
                                'count' => $summary['all'] ?? 0,
                                'hint' => trans('admin/hardware/message.pending_return.filter_all_hint'),
                            ],
                            'overdue' => [
                                'label' => trans('admin/hardware/message.pending_return.filter_overdue'),
                                'count' => $summary['overdue'] ?? 0,
                                'hint' => trans('admin/hardware/message.pending_return.filter_overdue_hint'),
                            ],
                            'today' => [
                                'label' => trans('admin/hardware/message.pending_return.filter_today'),
                                'count' => $summary['today'] ?? 0,
                                'hint' => trans('admin/hardware/message.pending_return.filter_today_hint'),
                            ],
                            'week' => [
                                'label' => trans('admin/hardware/message.pending_return.filter_week'),
                                'count' => $summary['week'] ?? 0,
                                'hint' => trans('admin/hardware/message.pending_return.filter_week_hint'),
                            ],
                            'no_date' => [
                                'label' => trans('admin/hardware/message.pending_return.filter_no_date'),
                                'count' => $summary['no_date'] ?? 0,
                                'hint' => trans('admin/hardware/message.pending_return.filter_no_date_hint'),
                            ],
                        ];

                        $sourceFilters = [
                            'all' => [
                                'label' => trans('admin/hardware/message.pending_return.source_all'),
                                'count' => $sourceSummary['all'] ?? 0,
                                'hint' => trans('admin/hardware/message.pending_return.source_all_hint'),
                            ],
                            'manual' => [
                                'label' => trans('admin/hardware/message.pending_return.source_manual'),
                                'count' => $sourceSummary['manual'] ?? 0,
                                'hint' => trans('admin/hardware/message.pending_return.source_manual_hint'),
                            ],
                            'reminder' => [
                                'label' => trans('admin/hardware/message.pending_return.source_reminder_filter'),
                                'count' => $sourceSummary['reminder'] ?? 0,
                                'hint' => trans('admin/hardware/message.pending_return.source_reminder_hint'),
                            ],
                            'borrowed' => [
                                'label' => trans('admin/hardware/message.pending_return.source_borrowed'),
                                'count' => $sourceSummary['borrowed'] ?? 0,
                                'hint' => trans('admin/hardware/message.pending_return.source_borrowed_hint'),
                            ],
                        ];

                        $dueMeta = function ($expectedDate) use ($today, $weekEnd) {
                            $date = $expectedDate ? \Illuminate\Support\Carbon::parse($expectedDate) : null;

                            if (! $date) {
                                return [
                                    'rowClass' => '',
                                    'labelClass' => 'pending-return-label-muted',
                                    'text' => trans('admin/hardware/message.pending_return.no_expected_date_short'),
                                    'priority' => false,
                                ];
                            }

                            if ($date->lt($today)) {
                                return [
                                    'rowClass' => 'pending-return-row-overdue',
                                    'labelClass' => 'pending-return-label-overdue',
                                    'text' => trans('admin/hardware/message.pending_return.overdue_days', [
                                        'days' => max(1, $date->diffInDays($today)),
                                    ]),
                                    'priority' => true,
                                ];
                            }

                            if ($date->isSameDay($today)) {
                                return [
                                    'rowClass' => 'pending-return-row-today',
                                    'labelClass' => 'pending-return-label-soon',
                                    'text' => trans('admin/hardware/message.pending_return.due_today'),
                                    'priority' => true,
                                ];
                            }

                            if ($date->lte($weekEnd)) {
                                return [
                                    'rowClass' => 'pending-return-row-week',
                                    'labelClass' => 'pending-return-label-good',
                                    'text' => trans('admin/hardware/message.pending_return.due_in_days', [
                                        'days' => max(1, $today->diffInDays($date)),
                                    ]),
                                    'priority' => false,
                                ];
                            }

                            return [
                                'rowClass' => '',
                                'labelClass' => 'pending-return-label-muted',
                                'text' => trans('admin/hardware/message.pending_return.on_track'),
                                'priority' => false,
                            ];
                        };

                        $sourceMeta = function (array $row) {
                            $replacementAsset = $row['replacementAsset'];
                            $source = $row['source'];

                            if ($source === 'borrowed_status') {
                                return [
                                    'class' => 'pending-return-role-borrowed',
                                    'title' => trans('admin/hardware/message.pending_return.source_borrowed'),
                                    'role' => trans('admin/hardware/message.pending_return.flow_borrowed_asset'),
                                    'desc' => trans('admin/hardware/message.pending_return.related_borrowed_status_hint'),
                                ];
                            }

                            if ($source === 'reminder') {
                                return [
                                    'class' => 'pending-return-role-reminder',
                                    'title' => trans('admin/hardware/message.pending_return.source_reminder_filter'),
                                    'role' => trans('admin/hardware/message.pending_return.flow_reminder_asset'),
                                    'desc' => trans('admin/hardware/message.pending_return.related_reminder_only_hint'),
                                ];
                            }

                            if ($replacementAsset) {
                                return [
                                    'class' => 'pending-return-role-standard',
                                    'title' => trans('admin/hardware/message.pending_return.source_manual'),
                                    'role' => trans('admin/hardware/message.pending_return.flow_old_asset'),
                                    'desc' => trans('admin/hardware/message.pending_return.source_manual_hint'),
                                ];
                            }

                            return [
                                'class' => 'pending-return-role-standard',
                                'title' => trans('admin/hardware/message.pending_return.related_current_asset_only'),
                                'role' => trans('admin/hardware/message.pending_return.flow_pending_asset'),
                                'desc' => trans('admin/hardware/message.pending_return.related_current_asset_only_hint'),
                            ];
                        };

                        $priorityRows = $visibleRows
                            ->filter(function (array $row) use ($dueMeta) {
                                return $dueMeta($row['expectedDate'])['priority'];
                            })
                            ->take(3)
                            ->values();

                        $activeQuery = [];
                        if ($dueFilter !== 'all') {
                            $activeQuery['due'] = $dueFilter;
                        }
                        if ($sourceFilter !== 'all') {
                            $activeQuery['source'] = $sourceFilter;
                        }
                        $exportQuery = $activeQuery;
                        if ($search !== '') {
                            $exportQuery['search'] = $search;
                        }
                    @endphp

                    <div class="pending-return-overview">
                        @foreach ($pendingReturnFilters as $filterKey => $filter)
                            @php
                                $filterQuery = [];
                                if ($filterKey !== 'all') {
                                    $filterQuery['due'] = $filterKey;
                                }
                                if ($sourceFilter !== 'all') {
                                    $filterQuery['source'] = $sourceFilter;
                                }
                                if ($search !== '') {
                                    $filterQuery['search'] = $search;
                                }
                            @endphp
                            <a
                                href="{{ route('hardware.pending-returns.index', $filterQuery) }}"
                                class="pending-return-overview-tile pending-return-overview-{{ $filterKey }} {{ $dueFilter === $filterKey ? 'active' : '' }}"
                            >
                                <span class="pending-return-overview-label">{{ $filter['label'] }}</span>
                                <span class="pending-return-overview-count">{{ $filter['count'] }}</span>
                                <span class="pending-return-overview-hint">{{ $filter['hint'] }}</span>
                            </a>
                        @endforeach
                    </div>

                    <div class="pending-return-type-tabs" aria-label="{{ trans('admin/hardware/message.pending_return.source_filter_label') }}">
                        @foreach ($sourceFilters as $filterKey => $filter)
                            @php
                                $sourceQuery = [];
                                if ($dueFilter !== 'all') {
                                    $sourceQuery['due'] = $dueFilter;
                                }
                                if ($filterKey !== 'all') {
                                    $sourceQuery['source'] = $filterKey;
                                }
                                if ($search !== '') {
                                    $sourceQuery['search'] = $search;
                                }
                            @endphp
                            <a
                                href="{{ route('hardware.pending-returns.index', $sourceQuery) }}"
                                class="pending-return-type-tab {{ $sourceFilter === $filterKey ? 'active' : '' }}"
                                title="{{ $filter['hint'] }}"
                            >
                                <span class="pending-return-type-main">
                                    {{ $filter['label'] }}
                                    <span class="pending-return-type-count">{{ $filter['count'] }}</span>
                                </span>
                                <span class="pending-return-type-hint">{{ $filter['hint'] }}</span>
                            </a>
                        @endforeach
                    </div>

                    @if ($priorityRows->isNotEmpty())
                        <div class="pending-return-priority">
                            <div class="pending-return-priority-header">
                                <div class="pending-return-priority-title">
                                    <x-icon type="warning" />
                                    {{ trans('admin/hardware/message.pending_return.priority_title') }}
                                </div>
                                <div class="pending-return-priority-meta">
                                    {{ trans('admin/hardware/message.pending_return.priority_hint') }}
                                </div>
                            </div>
                            <div class="pending-return-priority-list">
                                @foreach ($priorityRows as $priorityRow)
                                    @php
                                        $asset = $priorityRow['asset'];
                                        $currentUser = $priorityRow['user'];
                                        $expectedDate = $priorityRow['expectedDate'];
                                        $priorityDueMeta = $dueMeta($expectedDate);
                                        $prioritySourceMeta = $sourceMeta($priorityRow);
                                    @endphp
                                    <div class="pending-return-priority-item">
                                        <div>
                                            <span class="pending-return-role {{ $prioritySourceMeta['class'] }}">{{ $prioritySourceMeta['role'] }}</span>
                                            <span class="pending-return-label {{ $priorityDueMeta['labelClass'] }}">{{ $priorityDueMeta['text'] }}</span>
                                        </div>
                                        <div class="pending-return-priority-asset">
                                            @if ($asset)
                                                <a href="{{ route('hardware.show', $asset) }}">{{ $asset->asset_tag }}</a>
                                            @else
                                                -
                                            @endif
                                        </div>
                                        <div class="pending-return-meta">
                                            {{ $currentUser ? $currentUser->present()->fullName() : '-' }}
                                            @if ($expectedDate)
                                                · {{ Helper::getFormattedDateObject($expectedDate, 'date', false) }}
                                            @endif
                                        </div>
                                        <div class="pending-return-priority-reason">
                                            {{ $priorityRow['reason'] ?: '-' }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="pending-returns-command-bar">
                        <div class="pending-returns-hint">
                            {{ trans('admin/hardware/message.pending_return.dashboard_hint') }}
                        </div>

                        <form method="get" action="{{ route('hardware.pending-returns.index') }}" class="pending-returns-toolbar">
                            @if ($dueFilter !== 'all')
                                <input type="hidden" name="due" value="{{ $dueFilter }}">
                            @endif
                            @if ($sourceFilter !== 'all')
                                <input type="hidden" name="source" value="{{ $sourceFilter }}">
                            @endif
                            <input
                                type="search"
                                name="search"
                                class="form-control"
                                value="{{ $search }}"
                                placeholder="{{ trans('admin/hardware/message.pending_return.list_search_placeholder') }}"
                            >
                            <button type="submit" class="btn btn-primary">
                                <x-icon type="search" />
                                {{ trans('general.search') }}
                            </button>
                            @if ($search !== '')
                                <a href="{{ route('hardware.pending-returns.index', $activeQuery) }}" class="btn btn-default">
                                    {{ trans('admin/hardware/general.clear') }}
                                </a>
                            @endif
                            <a href="{{ route('hardware.pending-returns.export', $exportQuery) }}" class="btn btn-default">
                                <x-icon type="download" />
                                {{ trans('admin/hardware/message.pending_return.export_list') }}
                            </a>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover pending-return-table">
                            <thead>
                                <tr>
                                    <th class="pending-return-asset-col">{{ trans('admin/hardware/message.pending_return.asset_to_handle') }}</th>
                                    <th class="pending-return-user-col">{{ trans('general.current_user') }}</th>
                                    <th class="pending-return-status-col">{{ trans('admin/hardware/message.pending_return.return_status') }}</th>
                                    <th class="pending-return-actions-col text-right">{{ trans('general.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($pendingReturns as $pendingReturn)
                                    @php
                                        $asset = $pendingReturn['asset'];
                                        $replacementAsset = $pendingReturn['replacementAsset'];
                                        $currentUser = $pendingReturn['user'];
                                        $expectedDate = $pendingReturn['expectedDate'];
                                        $createdAt = $pendingReturn['createdAt'];
                                        $creator = $pendingReturn['creator'];
                                        $isBorrowedStatus = $pendingReturn['source'] === 'borrowed_status';
                                        $isReminder = $pendingReturn['source'] === 'reminder';
                                        $rowDueMeta = $dueMeta($expectedDate);
                                        $rowSourceMeta = $sourceMeta($pendingReturn);
                                        $detailId = 'pending-return-detail-'.$loop->iteration.'-'.($asset ? $asset->id : 'missing');
                                    @endphp
                                    <tr class="pending-return-main-row {{ $rowDueMeta['rowClass'] }}">
                                        <td>
                                            @if ($asset)
                                                <div class="pending-return-asset">
                                                    <span class="pending-return-role {{ $rowSourceMeta['class'] }}">
                                                        {{ $rowSourceMeta['role'] }}
                                                    </span>
                                                    <a href="{{ route('hardware.show', $asset) }}">{{ $asset->asset_tag }}</a>
                                                </div>
                                                <div class="pending-return-meta">
                                                    {{ $asset->name ?: optional($asset->model)->name ?: '-' }}
                                                </div>
                                                <div class="pending-return-meta-line">
                                                    SN: {{ $asset->serial ?: '-' }}
                                                </div>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>
                                            @if ($currentUser)
                                                <div class="pending-return-person">{!! $currentUser->present()->nameUrl() !!}</div>
                                                <div class="pending-return-meta">
                                                    {{ optional($currentUser->department)->name ?: $currentUser->username }}
                                                </div>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="pending-return-status-cell">
                                            <div class="pending-return-status-head">
                                                @if ($expectedDate)
                                                    <span class="pending-return-label {{ $rowDueMeta['labelClass'] }}">
                                                        {{ Helper::getFormattedDateObject($expectedDate, 'date', false) }}
                                                    </span>
                                                    <span class="pending-return-status-text">{{ $rowDueMeta['text'] }}</span>
                                                @else
                                                    <span class="pending-return-label pending-return-label-muted">
                                                        {{ trans('admin/hardware/message.pending_return.no_expected_date') }}
                                                    </span>
                                                    <span class="pending-return-status-text">{{ trans('admin/hardware/message.pending_return.no_expected_date_short') }}</span>
                                                @endif
                                            </div>
                                            <div class="pending-return-work-summary" title="{{ $pendingReturn['reason'] ?: trans('admin/hardware/message.pending_return.no_reason') }}">
                                                {{ $pendingReturn['reason'] ?: trans('admin/hardware/message.pending_return.no_reason') }}
                                            </div>
                                            @if ($replacementAsset)
                                                <div class="pending-return-meta">
                                                    {{ trans('admin/hardware/message.pending_return.related_new_asset') }}:
                                                    <a href="{{ route('hardware.show', $replacementAsset) }}">{{ $replacementAsset->asset_tag }}</a>
                                                </div>
                                            @elseif ($isBorrowedStatus || $isReminder)
                                                <div class="pending-return-meta">{{ $rowSourceMeta['desc'] }}</div>
                                            @endif
                                            @if ($pendingReturn['note'])
                                                <div class="pending-return-meta">
                                                    {{ \Illuminate\Support\Str::limit($pendingReturn['note'], 34) }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="text-right pending-return-actions">
                                            <button class="btn btn-sm btn-default" type="button" data-toggle="collapse" data-target="#{{ $detailId }}" aria-expanded="false" aria-controls="{{ $detailId }}" title="{{ trans('admin/hardware/message.pending_return.detail_toggle') }}">
                                                <x-icon type="caret-down" />
                                            </button>
                                            @if ($asset)
                                                @can('update', $asset)
                                                    <a href="{{ $isReminder ? route('hardware.pending-return.reminder.create', $asset) : route('hardware.pending-return.create', $asset) }}" class="btn btn-sm btn-warning" title="{{ $isBorrowedStatus ? trans('admin/hardware/message.pending_return.convert_borrowed_to_pending_return') : ($isReminder ? trans('admin/hardware/message.pending_return.edit_pending_return_reminder') : trans('admin/hardware/message.pending_return.edit_pending_return')) }}">
                                                        <x-icon type="edit" />
                                                    </a>
                                                @endcan
                                                @can('checkin', $asset)
                                                    <a href="{{ route('hardware.checkin.create', $asset) }}" class="btn btn-sm btn-primary" title="{{ trans('admin/hardware/message.pending_return.checkin_now') }}">
                                                        <x-icon type="checkin" />
                                                    </a>
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                    <tr class="collapse pending-return-detail-row" id="{{ $detailId }}">
                                        <td colspan="4">
                                            <div class="pending-return-detail-panel">
                                                <div class="pending-return-detail-grid">
                                                    <div class="pending-return-detail-block">
                                                        <div class="pending-return-detail-label">{{ trans('admin/hardware/message.pending_return.business_type') }}</div>
                                                        <div class="pending-return-detail-value">
                                                            {{ $rowSourceMeta['title'] }}
                                                            <div class="pending-return-meta">{{ $rowSourceMeta['desc'] }}</div>
                                                        </div>
                                                    </div>
                                                    <div class="pending-return-detail-block">
                                                        <div class="pending-return-detail-label">{{ trans('general.pending_return_reason') }}</div>
                                                        <div class="pending-return-detail-value">{{ $pendingReturn['reason'] ?: '-' }}</div>
                                                    </div>
                                                    <div class="pending-return-detail-block">
                                                        <div class="pending-return-detail-label">{{ trans('general.notes') }}</div>
                                                        <div class="pending-return-detail-value">{{ $pendingReturn['note'] ?: '-' }}</div>
                                                    </div>
                                                    <div class="pending-return-detail-block">
                                                        <div class="pending-return-detail-label">{{ trans('admin/hardware/message.pending_return.asset_details') }}</div>
                                                        <div class="pending-return-detail-value">
                                                            {{ optional($asset?->assetstatus)->name ?: '-' }}
                                                            · {{ optional($asset?->location)->name ?: '-' }}
                                                            · SN: {{ $asset?->serial ?: '-' }}
                                                        </div>
                                                    </div>
                                                    <div class="pending-return-detail-block">
                                                        <div class="pending-return-detail-label">{{ trans('admin/hardware/message.pending_return.related_details') }}</div>
                                                        <div class="pending-return-detail-value">
                                                            @if ($replacementAsset)
                                                                <span class="pending-return-role pending-return-role-new">{{ trans('admin/hardware/message.pending_return.related_new_asset') }}</span>
                                                                <a href="{{ route('hardware.show', $replacementAsset) }}">{{ $replacementAsset->asset_tag }}</a>
                                                                <div class="pending-return-meta">
                                                                    {{ $replacementAsset->name ?: optional($replacementAsset->model)->name ?: '-' }}
                                                                    · SN: {{ $replacementAsset->serial ?: '-' }}
                                                                </div>
                                                            @elseif ($isBorrowedStatus)
                                                                {{ trans('admin/hardware/message.pending_return.related_borrowed_status_hint') }}
                                                            @elseif ($isReminder)
                                                                {{ trans('admin/hardware/message.pending_return.related_reminder_only_hint') }}
                                                            @else
                                                                {{ trans('admin/hardware/message.pending_return.related_current_asset_only_hint') }}
                                                            @endif
                                                        </div>
                                                    </div>
                                                    <div class="pending-return-detail-block">
                                                        <div class="pending-return-detail-label">{{ trans('admin/hardware/message.pending_return.created_details') }}</div>
                                                        <div class="pending-return-detail-value">
                                                            {{ $createdAt ? Helper::getFormattedDateObject($createdAt, 'datetime', false) : '-' }}
                                                            @if ($creator)
                                                                <div class="pending-return-meta">
                                                                    {{ trans('general.created_by') }}:
                                                                    {!! $creator->present()->nameUrl() !!}
                                                                </div>
                                                            @endif
                                                            @if (! empty($pendingReturn['sourceLabel']))
                                                                <div class="pending-return-meta">{{ $pendingReturn['sourceLabel'] }}</div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                    <div class="pending-return-detail-block">
                                                        <div class="pending-return-detail-label">{{ trans('general.expected_return_date') }}</div>
                                                        <div class="pending-return-detail-value">
                                                            @if ($expectedDate)
                                                                {{ Helper::getFormattedDateObject($expectedDate, 'date', false) }}
                                                                <div class="pending-return-meta">{{ $rowDueMeta['text'] }}</div>
                                                            @else
                                                                {{ trans('admin/hardware/message.pending_return.no_expected_date') }}
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">
                                            {{ trans('admin/hardware/message.pending_return.list_empty') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $pendingReturns->links() }}
                </div>
            </div>
        </div>
    </div>
@stop
