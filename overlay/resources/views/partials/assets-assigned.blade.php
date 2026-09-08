<script nonce="{{ csrf_token() }}">

    // create the assigned assets listing box for the right side of the screen
    $(function() {
        var pendingReturnInput = $('#pending_return_asset_id');
        var pendingReturnPanel = $('#checkout_pending_return_panel');
        var pendingReturnReason = $('#pending_return_reason');
        var pendingReturnLabel = $('#pending_return_asset_label');
        var pendingReturnSelectText = '{{ trans('admin/hardware/message.pending_return.checkout_select_action') }}';
        var pendingReturnCancelText = '{{ trans('admin/hardware/message.pending_return.checkout_cancel_action') }}';

        function escapeHtml(value) {
            return $('<div>').text(value || '').html();
        }

        function escapeAttr(value) {
            return escapeHtml(value).replace(/"/g, '&quot;');
        }

        function refreshPendingReturnButtons() {
            var selectedAssetId = String(pendingReturnInput.val() || '');

            $('.select-pending-return-asset').each(function () {
                var button = $(this);
                var isSelected = selectedAssetId !== '' && String(button.data('asset-id')) === selectedAssetId;

                button
                    .toggleClass('btn-warning', isSelected)
                    .toggleClass('btn-default', !isSelected)
                    .text(isSelected ? pendingReturnCancelText : pendingReturnSelectText);
            });
        }

        function setPendingReturnAsset(assetId, label) {
            pendingReturnInput.val(assetId);
            pendingReturnLabel.text(label);
            pendingReturnReason.prop('required', true);
            pendingReturnPanel.slideDown(120);
            refreshPendingReturnButtons();
        }

        function clearPendingReturnAsset() {
            pendingReturnInput.val('');
            pendingReturnLabel.text('-');
            pendingReturnReason.prop('required', false);
            pendingReturnPanel.slideUp(120);
            refreshPendingReturnButtons();
        }

        $('#clear_pending_return_asset').on('click', clearPendingReturnAsset);

        $('input[name=checkout_to_type]').on('change', function () {
            if ($('input[name=checkout_to_type]:checked').val() !== 'user') {
                clearPendingReturnAsset();
            }
        });

        $('#current_assets_content').on('click', '.select-pending-return-asset', function () {
            var assetId = String($(this).data('asset-id'));

            if (String(pendingReturnInput.val()) === assetId) {
                clearPendingReturnAsset();
                return;
            }

            setPendingReturnAsset(assetId, $(this).data('asset-label'));
        });

        if (pendingReturnInput.val()) {
            pendingReturnReason.prop('required', true);
        }

        $('#assigned_user').on("change",function () {
            var userid = $('#assigned_user option:selected').val();

            if(userid=='') {
                console.warn('no user selected');
                clearPendingReturnAsset();
                $('#current_assets_box').fadeOut();
                $('#current_assets_content').html("");
            } else {

                $.ajax({
                    type: 'GET',
                    url: '{{ config('app.url') }}/api/v1/users/' + userid + '/assets',
                    headers: {
                        "X-Requested-With": 'XMLHttpRequest',
                        "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr('content')
                    },

                    dataType: 'json',
                    success: function (data) {
                        $('#current_assets_box').fadeIn();

                        var table_html = '<div class="row">';
                        table_html += '<div class="col-md-12">';
                        table_html += '<table class="table table-striped">';
                        table_html += '<thead><tr>';
                        table_html += '<th></th>';
                        table_html += '<th>{{ trans('admin/hardware/form.name') }}</th>';
                        table_html += '<th>{{ trans('admin/hardware/form.tag') }}</th>';
                        table_html += '<th>{{ trans('admin/hardware/form.serial') }}</th>';
                        table_html += '<th>{{ trans('general.pending_return') }}</th>';
                        table_html += '</tr></thead><tbody>';

                        $('#current_assets_content').append('');

                        if (data.rows.length > 0) {

                            for (var i in data.rows) {
                                var asset = data.rows[i];
                                var modelName = asset.model && asset.model.name ? asset.model.name : '';
                                var assetName = asset.name ? asset.name : modelName;
                                var serial = asset.serial || '-';
                                var assetLabel = asset.asset_tag + (modelName ? ' · ' + modelName : '') + ' · SN: ' + serial;
                                var isSelectedPendingReturn = (String(pendingReturnInput.val()) === String(asset.id));
                                var selectedClass = isSelectedPendingReturn ? 'btn-warning' : 'btn-default';
                                var buttonText = isSelectedPendingReturn ? pendingReturnCancelText : pendingReturnSelectText;
                                table_html += '<tr>';
                                if (asset.image != null) {
                                    table_html += '<td class="col-md-1"><a href="' + asset.image + '" data-toggle="lightbox" data-type="image"><img src="' + asset.image + '" style="max-height: {{ $snipeSettings->thumbnail_max_h }}px; width: auto;"></a></td>';
                                } else {
                                    table_html += "<td></td> ";
                                }
                                table_html += '<td><a href="{{ config('app.url') }}/hardware/' + asset.id + '">';

                                if ((asset.name == '') && (asset.name != null)) {
                                    table_html += " " + escapeHtml(modelName);
                                } else {
                                    table_html += escapeHtml(assetName);
                                    table_html += modelName ? " (" + escapeHtml(modelName) + ")" : "";
                                }

                                table_html += '</a></td>';
                                table_html += '<td class="col-md-3">' + escapeHtml(asset.asset_tag) + '</td>';
                                table_html += '<td class="col-md-3">' + escapeHtml(serial) + '</td>';
                                table_html += '<td class="col-md-2"><button type="button" class="btn btn-xs ' + selectedClass + ' select-pending-return-asset" data-asset-id="' + asset.id + '" data-asset-label="' + escapeAttr(assetLabel) + '">' + escapeHtml(buttonText) + '</button></td>';
                                table_html += "</tr>";
                            }
                        } else {
                            table_html += '<tr><td colspan="5">{{ trans('admin/users/message.user_has_no_assets_assigned') }}</td></tr>';
                        }
                        $('#current_assets_content').html(table_html + '</tbody></table></div></div>');

                    },
                    error: function (data) {
                        clearPendingReturnAsset();
                        $('#current_assets_box').fadeOut();
                    }
                });
            }
        });
    });
</script>
