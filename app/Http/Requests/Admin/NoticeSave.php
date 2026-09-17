<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NoticeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'title' => 'required',
            'content' => 'required',
            'img_url' => 'nullable|url',
            'tags' => 'nullable|array',
            'auto_popup' => 'nullable|boolean',
            'popup_interval' => 'required_if:auto_popup,1|nullable|integer|min:1|max:8760'
        ];
    }

    public function messages()
    {
        return [
            'title.required' => '标题不能为空',
            'content.required' => '内容不能为空',
            'img_url.url' => '图片URL格式不正确',
            'tags.array' => '标签格式不正确',
            'auto_popup.boolean' => '自动弹出开关格式不正确',
            'popup_interval.required_if' => '开启自动弹出后必须填写弹出间隔',
            'popup_interval.integer' => '弹出间隔必须是整数小时',
            'popup_interval.min' => '弹出间隔至少为1小时',
            'popup_interval.max' => '弹出间隔不能超过8760小时'
        ];
    }
}
