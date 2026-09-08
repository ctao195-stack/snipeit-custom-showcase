<?php

namespace App\Http\Requests;

class AssetCheckinRequest extends Request
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

        $rules = [];

            if($settings->require_checkinout_notes) {
            $rules['note'] = 'string|required';
        }

        $rules['mentioned_assets'] = 'array';
        $rules['mentioned_assets.*'] = 'integer|exists:assets,id';
        $rules['actionlog_attachments'] = 'nullable|array|max:10';
        $rules['actionlog_attachments.*'] = 'bail|nullable|file|mimes:jpg,jpeg,png,gif,webp,bmp,heic,heif|max:10240';

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

    public function response(array $errors)
    {
        return $this->redirector->back()->withInput()->withErrors($errors, $this->errorBag);
    }
}
