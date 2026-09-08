<style>
    .actionlog-attachment-dropzone {
        background: #f8fbfd;
        border: 1px dashed #9db8c9;
        border-radius: 6px;
        cursor: pointer;
        padding: 14px;
        transition: border-color .15s ease, background .15s ease;
    }

    .actionlog-attachment-dropzone.is-dragover {
        background: #edf7fd;
        border-color: #3c8dbc;
    }

    .actionlog-attachment-dropzone-title {
        color: #2c5872;
        font-weight: 700;
    }

    .actionlog-attachment-preview {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 10px;
    }

    .actionlog-attachment-preview-item {
        align-items: center;
        background: #fff;
        border: 1px solid #dbe3ea;
        border-radius: 6px;
        display: flex;
        gap: 8px;
        max-width: 100%;
        padding: 6px;
    }

    .actionlog-attachment-preview-item img {
        border-radius: 4px;
        height: 46px;
        object-fit: cover;
        width: 46px;
    }

    .actionlog-attachment-preview-name {
        color: #344054;
        max-width: 260px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
</style>

<div class="form-group {{ $errors->has('actionlog_attachments') || $errors->has('actionlog_attachments.*') ? 'error' : '' }}">
    <label for="actionlog_attachments" class="col-md-3 control-label">
        {{ trans('general.operation_evidence') }}
    </label>
    <div class="col-md-8">
        <div class="actionlog-attachment-dropzone" id="actionlog_attachment_dropzone">
            <div class="actionlog-attachment-dropzone-title">
                <x-icon type="paperclip" />
                {{ trans('general.operation_evidence_upload') }}
            </div>
            <div class="text-muted">
                {{ trans('general.operation_evidence_help') }}
            </div>
            <label class="btn btn-xs btn-default" id="actionlog_attachment_pick" for="actionlog_attachments" style="margin-top: 8px;">
                {{ trans('button.select_file') }}
            </label>
            <input
                type="file"
                name="actionlog_attachments[]"
                id="actionlog_attachments"
                accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,.heic,.heif"
                multiple
                style="position:absolute; left:-9999px; width:1px; height:1px; opacity:0;"
            >
        </div>
        <div class="actionlog-attachment-preview" id="actionlog_attachment_preview"></div>
        <p class="help-block">{{ trans('general.operation_evidence_limit_help') }}</p>
        {!! $errors->first('actionlog_attachments', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
        @foreach ($errors->get('actionlog_attachments.*') as $attachmentErrors)
            @foreach ($attachmentErrors as $attachmentError)
                <span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> {{ $attachmentError }}</span>
            @endforeach
        @endforeach
    </div>
</div>

<script nonce="{{ csrf_token() }}">
    (function () {
        function ready(callback) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', callback);
                return;
            }

            callback();
        }

        ready(function () {
            var input = document.getElementById('actionlog_attachments');
            var dropzone = document.getElementById('actionlog_attachment_dropzone');
            var picker = document.getElementById('actionlog_attachment_pick');
            var preview = document.getElementById('actionlog_attachment_preview');
            var selectedFiles = [];
            var allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'];
            var mimeExtensionMap = {
                'image/jpeg': 'jpg',
                'image/jpg': 'jpg',
                'image/png': 'png',
                'image/x-png': 'png',
                'image/gif': 'gif',
                'image/webp': 'webp',
                'image/bmp': 'bmp',
                'image/x-ms-bmp': 'bmp',
                'image/heic': 'heic',
                'image/heif': 'heif'
            };
            var maxFiles = 10;

            if (!input || !dropzone || !picker || !preview) {
                return;
            }

            function fileExtension(file) {
                return (file.name || '').split('.').pop().toLowerCase();
            }

            function isAllowedImage(file) {
                var extension = fileExtension(file);
                var mimeType = (file.type || '').toLowerCase();

                return allowedExtensions.indexOf(extension) !== -1 || Object.prototype.hasOwnProperty.call(mimeExtensionMap, mimeType);
            }

            function screenshotFileName(extension) {
                var now = new Date();
                var pad = function (value) {
                    return String(value).padStart(2, '0');
                };

                return [
                    '截图',
                    now.getFullYear(),
                    pad(now.getMonth() + 1),
                    pad(now.getDate()),
                    '-',
                    pad(now.getHours()),
                    pad(now.getMinutes()),
                    pad(now.getSeconds())
                ].join('') + '.' + extension;
            }

            function normalizeImageFile(file) {
                if (!file || !file.size) {
                    return null;
                }

                var extension = fileExtension(file);
                var mimeType = (file.type || '').toLowerCase();

                if (allowedExtensions.indexOf(extension) !== -1) {
                    return file;
                }

                if (!Object.prototype.hasOwnProperty.call(mimeExtensionMap, mimeType) || typeof File === 'undefined') {
                    return file;
                }

                return new File([file], screenshotFileName(mimeExtensionMap[mimeType]), {
                    type: mimeType === 'image/x-png' ? 'image/png' : mimeType,
                    lastModified: Date.now()
                });
            }

            function refreshInputFiles() {
                if (typeof DataTransfer === 'undefined') {
                    return;
                }

                var dataTransfer = new DataTransfer();
                selectedFiles.forEach(function (file) {
                    dataTransfer.items.add(file);
                });
                input.files = dataTransfer.files;
            }

            function renderPreview() {
                preview.innerHTML = '';

                selectedFiles.forEach(function (file, index) {
                    var item = document.createElement('div');
                    item.className = 'actionlog-attachment-preview-item';

                    if (/^image\//.test(file.type || '') && !/hei[cf]$/i.test(file.name || '')) {
                        var img = document.createElement('img');
                        img.alt = file.name;
                        img.src = URL.createObjectURL(file);
                        item.appendChild(img);
                    } else {
                        var icon = document.createElement('i');
                        icon.className = 'far fa-image fa-2x text-muted';
                        item.appendChild(icon);
                    }

                    var name = document.createElement('span');
                    name.className = 'actionlog-attachment-preview-name';
                    name.textContent = file.name || '{{ trans('general.operation_evidence_clipboard_image') }}';
                    item.appendChild(name);

                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'btn btn-xs btn-default';
                    remove.textContent = '{{ trans('button.delete') }}';
                    remove.addEventListener('click', function () {
                        selectedFiles.splice(index, 1);
                        refreshInputFiles();
                        renderPreview();
                    });
                    item.appendChild(remove);

                    preview.appendChild(item);
                });
            }

            function addFiles(files) {
                Array.prototype.slice.call(files || []).forEach(function (file) {
                    file = normalizeImageFile(file);

                    if (selectedFiles.length >= maxFiles || !file || !isAllowedImage(file)) {
                        return;
                    }

                    selectedFiles.push(file);
                });

                refreshInputFiles();
                renderPreview();
            }

            function filesFromClipboard(clipboardData) {
                var files = Array.prototype.slice.call(clipboardData.files || []);

                if (files.length > 0 || !clipboardData.items) {
                    return files;
                }

                return Array.prototype.slice.call(clipboardData.items).map(function (item) {
                    return item.kind === 'file' ? item.getAsFile() : null;
                }).filter(function (file) {
                    return file;
                });
            }

            picker.addEventListener('click', function (event) {
                event.stopPropagation();
            });

            input.addEventListener('click', function (event) {
                event.stopPropagation();
            });

            dropzone.addEventListener('click', function (event) {
                if (event.target === input || event.target === picker || picker.contains(event.target)) {
                    return;
                }

                input.click();
            });

            input.addEventListener('change', function () {
                addFiles(input.files);
            });

            var form = input.closest('form');
            if (form) {
                form.addEventListener('submit', function () {
                    if (selectedFiles.length === 0) {
                        input.disabled = true;
                        return;
                    }

                    input.disabled = false;
                    refreshInputFiles();
                });
            }

            dropzone.addEventListener('dragover', function (event) {
                event.preventDefault();
                dropzone.classList.add('is-dragover');
            });

            dropzone.addEventListener('dragleave', function () {
                dropzone.classList.remove('is-dragover');
            });

            dropzone.addEventListener('drop', function (event) {
                event.preventDefault();
                dropzone.classList.remove('is-dragover');
                addFiles(event.dataTransfer.files);
            });

            document.addEventListener('paste', function (event) {
                if (!event.clipboardData) {
                    return;
                }

                addFiles(filesFromClipboard(event.clipboardData));
            });
        });
    })();
</script>
