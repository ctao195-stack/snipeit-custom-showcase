<div>
    @if(session()->has('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if(session()->has('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif

    @unless($webhookConfigured)
        <div class="alert alert-warning">
            企业微信 webhook 未配置，当前页面可以预览，但不能发送。
        </div>
    @endunless

    <div class="row">
        <div class="col-md-7">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">企业微信推送 DIY 测试</h2>
                </div>
                <div class="box-body">
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label>推送类型</label>
                                <select class="form-control" wire:model.blur="message_type">
                                    @foreach($message_types as $type)
                                        <option value="{{ $type }}">{{ $type }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label>自定义标题</label>
                                <input type="text" class="form-control" wire:model.blur="custom_title" placeholder="不填则使用推送类型">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-sm-8">
                            <div class="form-group">
                                <label>搜索并带入资产</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" wire:model.blur="asset_search" placeholder="资产标签、SN、名称、型号">
                                    <span class="input-group-btn">
                                        <button type="button" class="btn btn-primary" wire:click="searchAssets">
                                            <i class="fas fa-search" aria-hidden="true"></i>
                                            搜索
                                        </button>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label>操作人</label>
                                <input type="text" class="form-control" wire:model.blur="operator_name">
                            </div>
                        </div>
                    </div>

                    @if($asset_results)
                        <div class="list-group" style="margin-top: -8px;">
                            @foreach($asset_results as $asset)
                                <button type="button" class="list-group-item" wire:click="selectAsset({{ $asset['id'] }})">
                                    <strong>{{ $asset['asset_tag'] }}</strong>
                                    <span class="text-muted">
                                        {{ $asset['name'] }} · {{ $asset['model'] }} · SN: {{ $asset['serial'] }} · {{ $asset['status'] }} · {{ $asset['location'] }}
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    <div class="row">
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label>资产标签</label>
                                <input type="text" class="form-control" wire:model.blur="asset_tag">
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label>资产名称</label>
                                <input type="text" class="form-control" wire:model.blur="asset_name">
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label>SN</label>
                                <input type="text" class="form-control" wire:model.blur="serial">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label>型号</label>
                                <input type="text" class="form-control" wire:model.blur="model_name">
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label>状态</label>
                                <input type="text" class="form-control" wire:model.blur="status_name">
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label>位置</label>
                                <input type="text" class="form-control" wire:model.blur="location_name">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label>采购价格</label>
                                <input type="text" class="form-control" wire:model.blur="purchase_cost">
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label>当前使用人</label>
                                <input type="text" class="form-control" wire:model.blur="assigned_name">
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="form-group">
                                <label>操作对象</label>
                                <input type="text" class="form-control" wire:model.blur="target_name" placeholder="领用人、归还人、接收人">
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="clearfix" style="margin-bottom: 10px;">
                        <strong>模拟变更</strong>
                        <button type="button" class="btn btn-default btn-xs pull-right" wire:click="addChange">
                            <i class="fas fa-plus" aria-hidden="true"></i>
                            添加一行
                        </button>
                    </div>

                    @foreach($changes as $index => $change)
                        <div class="row" wire:key="change-row-{{ $index }}">
                            <div class="col-sm-3">
                                <div class="form-group">
                                    <input type="text" class="form-control" wire:model.blur="changes.{{ $index }}.label" placeholder="字段">
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group">
                                    <input type="text" class="form-control" wire:model.blur="changes.{{ $index }}.old" placeholder="原值">
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group">
                                    <input type="text" class="form-control" wire:model.blur="changes.{{ $index }}.new" placeholder="新值">
                                </div>
                            </div>
                            <div class="col-sm-1">
                                <button type="button" class="btn btn-danger btn-sm" wire:click="removeChange({{ $index }})" title="删除">
                                    <i class="fas fa-times" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    @endforeach

                    <hr>

                    <div class="form-group">
                        <label>@关联资产</label>
                        <div class="input-group">
                            <input type="text" class="form-control" wire:model.blur="mention_search" placeholder="资产标签、SN、名称、型号">
                            <span class="input-group-btn">
                                <button type="button" class="btn btn-default" wire:click="searchMentionAssets">
                                    <i class="fas fa-search" aria-hidden="true"></i>
                                    搜索
                                </button>
                            </span>
                        </div>
                    </div>

                    @if($mention_results)
                        <div class="list-group" style="margin-top: -8px;">
                            @foreach($mention_results as $asset)
                                <button type="button" class="list-group-item" wire:click="addMentionAsset({{ $asset['id'] }})">
                                    <strong>{{ '@'.$asset['asset_tag'] }}</strong>
                                    <span class="text-muted">
                                        {{ $asset['name'] }} · {{ $asset['model'] }} · SN: {{ $asset['serial'] }}
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @if($mentions)
                        <div style="margin-bottom: 12px;">
                            @foreach($mentions as $mention)
                                <span class="label label-info" style="display: inline-block; margin: 0 6px 6px 0; padding: 7px 9px;">
                                    {{ '@'.$mention['asset_tag'] }}
                                    <button type="button" wire:click="removeMentionAsset({{ $mention['id'] }})" style="border: 0; background: transparent; color: #fff;">
                                        <i class="fas fa-times" aria-hidden="true"></i>
                                    </button>
                                </span>
                            @endforeach
                        </div>
                    @endif

                    <div class="form-group">
                        <label>备注</label>
                        <textarea class="form-control" rows="4" wire:model.blur="note" placeholder="这里可以手动写 @资产标签，能识别到的资产会自动变成链接"></textarea>
                    </div>
                </div>
                <div class="box-footer">
                    <a href="{{ route('settings.index') }}" class="btn btn-link">
                        {{ trans('general.cancel') }}
                    </a>
                    <button type="button" class="btn btn-primary pull-right" wire:click="sendTest" wire:loading.attr="disabled">
                        <i class="fas fa-paper-plane" aria-hidden="true"></i>
                        发送测试
                    </button>
                    <span class="pull-right" wire:loading style="padding: 7px 12px;">
                        <i class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                    </span>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h2 class="box-title">企业微信预览</h2>
                </div>
                <div class="box-body">
                    <pre style="min-height: 560px; white-space: pre-wrap; word-break: break-word; background: #f8fbff; border: 1px solid #d6e6f5; color: #233142; line-height: 1.7;">{{ $preview }}</pre>
                </div>
            </div>
        </div>
    </div>
</div>
