<script nonce="{{ csrf_token() }}">
    $(function () {
        var $note = $('#note');
        var $results = $('#asset_mention_results');
        var $inputs = $('#mentioned_assets_inputs');
        var $chips = $('#mentioned_assets_chips');
        var searchUrl = @json(route('hardware.mentions.search'));
        var request = null;
        var debounceTimer = null;
        var isComposing = false;
        var activeIndex = 0;
        var activeContext = null;
        var lastResults = [];

        function mentionContext() {
            var input = $note.get(0);
            var value = $note.val();
            var cursor = typeof input.selectionStart === 'number' ? input.selectionStart : value.length;
            var beforeCursor = value.substring(0, cursor);
            var atIndex = Math.max(beforeCursor.lastIndexOf('@'), beforeCursor.lastIndexOf('＠'));

            if (atIndex < 0) {
                return null;
            }

            var term = beforeCursor.substring(atIndex + 1);

            if (/[\s@＠]/.test(term)) {
                return null;
            }

            return {
                start: atIndex,
                end: cursor,
                term: term
            };
        }

        function hideResults() {
            $results.hide().empty();
            activeIndex = 0;
            lastResults = [];
        }

        function escapeText(text) {
            return $('<div>').text(text || '').html();
        }

        function assetMeta(asset) {
            var parts = [];

            if (asset.name) {
                parts.push(asset.name);
            }

            if (asset.model) {
                parts.push(asset.model);
            }

            if (asset.model_number) {
                parts.push(asset.model_number);
            }

            if (asset.serial) {
                parts.push('SN: ' + asset.serial);
            }

            if (asset.assigned_to) {
                parts.push('{{ trans('general.current_user') }}: ' + asset.assigned_to);
            }

            if (asset.status) {
                parts.push(asset.status);
            }

            if (asset.purchase_cost) {
                parts.push('{{ trans('general.purchase_cost') }}: ' + asset.purchase_cost);
            }

            if (asset.location) {
                parts.push('{{ trans('general.location') }}: ' + asset.location);
            }

            return parts.join(' · ');
        }

        function renderResults(results) {
            lastResults = results || [];
            activeIndex = 0;
            $results.empty();

            if (!lastResults.length) {
                $results.append($('<div class="asset-mention-empty">').text('{{ trans('general.no_results') }}'));
                $results.show();
                return;
            }

            $.each(lastResults, function (index, asset) {
                var $option = $('<div class="asset-mention-option" role="option">')
                    .attr('data-mention-index', index)
                    .toggleClass('is-active', index === activeIndex);
                var $title = $('<div class="asset-mention-title">').text('@' + asset.asset_tag);
                var $meta = $('<div class="asset-mention-meta">').html(escapeText(assetMeta(asset)));

                $option.append($title).append($meta);
                $results.append($option);
            });

            $results.show();
        }

        function searchMentions(context) {
            if (request) {
                request.abort();
            }

            request = $.ajax({
                url: searchUrl,
                dataType: 'json',
                data: {
                    search: context.term
                },
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).done(function (response) {
                activeContext = context;
                renderResults(response.results || []);
            });
        }

        function updateActiveOption() {
            $results.find('.asset-mention-option').removeClass('is-active');
            $results.find('[data-mention-index="' + activeIndex + '"]').addClass('is-active');
        }

        function addMentionedAsset(asset) {
            if (!asset || !asset.id || $('[data-mentioned-asset-input="' + asset.id + '"]').length) {
                return;
            }

            $('<input type="hidden" name="mentioned_assets[]">')
                .val(asset.id)
                .attr('data-mentioned-asset-input', asset.id)
                .appendTo($inputs);

            var $chip = $('<span class="asset-mention-chip">')
                .attr('data-mentioned-asset-chip', asset.id)
                .text('@' + asset.asset_tag + ' ');
            var $remove = $('<button type="button" class="asset-mention-remove">&times;</button>')
                .attr('data-remove-mentioned-asset', asset.id)
                .attr('aria-label', '{{ trans('general.delete') }}');

            $chip.append($remove).appendTo($chips);
        }

        function insertMention(asset) {
            if (!asset || !activeContext) {
                return;
            }

            var input = $note.get(0);
            var value = $note.val();
            var before = value.substring(0, activeContext.start);
            var after = value.substring(activeContext.end);
            var spacer = before && !/\s$/.test(before) ? ' ' : '';
            var mention = '@' + asset.asset_tag;
            var nextValue = before + spacer + mention + ' ' + after;
            var nextCursor = (before + spacer + mention + ' ').length;

            $note.val(nextValue);
            $note.focus();

            if (typeof input.setSelectionRange === 'function') {
                input.setSelectionRange(nextCursor, nextCursor);
            }

            addMentionedAsset(asset);
            hideResults();
        }

        function scheduleSearch() {
            if (isComposing) {
                return;
            }

            var context = mentionContext();

            if (!context) {
                hideResults();
                return;
            }

            activeContext = context;

            if (!context.term) {
                $results.empty()
                    .append($('<div class="asset-mention-empty">').text('{{ trans('general.type_at_to_search_assets') }}'))
                    .show();
                return;
            }

            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(function () {
                searchMentions(context);
            }, 180);
        }

        $note.on('compositionstart', function () {
            isComposing = true;
        });

        $note.on('compositionend', function () {
            isComposing = false;
            scheduleSearch();
        });

        $note.on('input keyup click', scheduleSearch);

        $note.on('keydown', function (event) {
            if (!$results.is(':visible') || !lastResults.length) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeIndex = Math.min(activeIndex + 1, lastResults.length - 1);
                updateActiveOption();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                updateActiveOption();
            } else if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                insertMention(lastResults[activeIndex]);
            } else if (event.key === 'Escape') {
                hideResults();
            }
        });

        $results.on('mouseenter', '.asset-mention-option', function () {
            activeIndex = parseInt($(this).attr('data-mention-index'), 10) || 0;
            updateActiveOption();
        });

        $results.on('mousedown', '.asset-mention-option', function (event) {
            event.preventDefault();
            var index = parseInt($(this).attr('data-mention-index'), 10) || 0;
            insertMention(lastResults[index]);
        });

        $chips.on('click', '[data-remove-mentioned-asset]', function () {
            var assetId = $(this).attr('data-remove-mentioned-asset');
            $('[data-mentioned-asset-input="' + assetId + '"]').remove();
            $('[data-mentioned-asset-chip="' + assetId + '"]').remove();
        });

        $(document).on('mousedown', function (event) {
            if (!$(event.target).closest('.asset-mention-wrap, #asset_mention_results').length) {
                hideResults();
            }
        });
    });
</script>
