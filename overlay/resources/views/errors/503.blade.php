@extends('layouts/basic')

{{-- Page title --}}
@section('title')
  {{ trans('general.maintenance_mode_title') }}
@parent
@stop

{{-- Page content --}}

@section('content')

<div class="container">
  <div class="row">
    <div class="col-md-8 col-md-offset-2">
      <div class="box box-warning" style="margin-top: 120px;">
        <div class="box-header with-border">
          <h1 class="box-title">
            <x-icon type="warning" class="text-orange" />
            系统正在维护
          </h1>
        </div>
        <div class="box-body">
          <p class="lead">服务暂时不可用，请稍后刷新页面重试。</p>
          <p class="text-muted">如果维护时间较长，请联系系统管理员确认进度。</p>
          <a class="btn btn-primary" href="{{ config('app.url') }}">返回控制面板</a>
        </div>
      </div>
    </div>
  </div>
</div>
@stop
