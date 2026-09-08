<?php

namespace App\Http\Requests;

class AssetCheckoutRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->removeEmptyActionlogAttachments();
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $settings = \App\Models\Setting::getSettings();

        $rules = [
            'assigned_user' => 'numeric|nullable|required_without_all:assigned_asset,assigned_location',
            'assigned_asset' => 'numeric|nullable|required_without_all:assigned_user,assigned_location',
            'assigned_location' => 'numeric|nullable|required_without_all:assigned_user,assigned_asset',
            'status_id'             => 'exists:status_labels,id,deployable,1',
            'checkout_to_type'      => 'required|in:asset,location,user',
            'checkout_at' => [
                'nullable',
                'date',
            ],
            'expected_checkin' => [
                'nullable',
                'date'
            ],
            'pending_return_asset_id' => [
                'nullable',
                'integer',
                'exists:assets,id',
            ],
            'pending_return_reason' => [
                'nullable',
                'required_with:pending_return_asset_id',
                'string',
                'max:191',
            ],
            'pending_return_expected_return_date' => [
                'nullable',
                'date',
            ],
            'pending_return_note' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'actionlog_attachments' => [
                'nullable',
                'array',
                'max:10',
            ],
            'actionlog_attachments.*' => [
                'bail',
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,gif,webp,bmp,heic,heif',
                'max:10240',
            ],
            ];

            if($settings->require_checkinout_notes) {
                $rules['note'] = 'required|string';
            }

            $rules['mentioned_assets'] = 'array';
            $rules['mentioned_assets.*'] = 'integer|exists:assets,id';

        return $rules;
    }

    public function attributes()
    {
        return [
            'actionlog_attachments' => trans('general.operation_evidence'),
            'actionlog_attachments.*' => trans('general.operation_evidence_image'),
        ];
    }

    public function messages()
    {
        return [
            'actionlog_attachments.max' => trans('general.operation_evidence_count_error'),
            'actionlog_attachments.*.file' => trans('general.operation_evidence_file_error'),
            'actionlog_attachments.*.mimes' => trans('general.operation_evidence_type_error'),
            'actionlog_attachments.*.max' => trans('general.operation_evidence_size_error'),
        ];
    }

    private function removeEmptyActionlogAttachments(): void
    {
        $files = $this->file('actionlog_attachments', []);

        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }

        $files = array_values(array_filter($files, function ($file) {
            if (! $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                return false;
            }

            return $file->getError() !== UPLOAD_ERR_NO_FILE;
        }));

        if ($files === []) {
            $this->files->remove('actionlog_attachments');
            $this->request->remove('actionlog_attachments');

            return;
        }

        $this->files->set('actionlog_attachments', $files);
    }
}
