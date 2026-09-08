@extends('layouts/basic')

{{-- Page title --}}
@section('title')
404
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
            页面不存在
          </h1>
        </div>
        <div class="box-body">
          <p class="lead">没有找到你要访问的页面。</p>
          <p class="text-muted">可能是地址输入错误、页面已移动，或该路径不是 Snipe-IT 的业务页面。</p>
          <a class="btn btn-primary" href="{{ config('app.url') }}">返回控制面板</a>
          <a class="btn btn-default" href="javascript:history.back()">返回上一页</a>
        </div>
      </div>
    </div>
  </div>
</div>
@stop
