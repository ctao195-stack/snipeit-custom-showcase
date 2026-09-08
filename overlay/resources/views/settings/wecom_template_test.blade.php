@extends('layouts/default')

{{-- Page title --}}
@section('title')
    企业微信推送测试
    @parent
@stop

@section('header_right')
    <a href="{{ route('settings.index') }}" class="btn btn-primary"> {{ trans('general.back') }}</a>
@stop

@section('content')
    @livewire('wecom-template-tester')
@stop
