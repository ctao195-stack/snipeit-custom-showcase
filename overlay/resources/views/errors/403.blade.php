@extends('layouts/basic')

{{-- Page title --}}
@section('title')
403
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
            <x-icon type="warning" class="text-yellow" />
            无法访问此页面
          </h1>
        </div>
        <div class="box-body">
          <p class="lead">当前账号没有访问该页面的权限，或者该路径已被系统安全策略拦截。</p>
          <p class="text-muted">如果你正在进行正常资产操作，请返回上一页后重试；如果多次出现，请联系系统管理员。</p>
          <a class="btn btn-primary" href="{{ config('app.url') }}">返回控制面板</a>
          <a class="btn btn-default" href="javascript:history.back()">返回上一页</a>
        </div>
      </div>
    </div>
  </div>
</div>
@stop
