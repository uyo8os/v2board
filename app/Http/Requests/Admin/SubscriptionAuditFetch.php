<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SubscriptionAuditFetch extends FormRequest
{
    public function rules()
    {
        return [
            'current' => 'nullable|integer|min:1|max:2147483647',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'time_from' => 'nullable|integer|min:0|max:4294967295',
            'time_to' => 'nullable|integer|min:0|max:4294967295',
            'user_id' => 'nullable|integer|min:1|max:2147483647',
            'email' => 'nullable|string|max:64',
            'ip' => 'nullable|ip',
            'ua' => 'nullable|string|max:512',
            'format' => ['nullable', 'string', 'max:64', 'regex:/^[\x20-\x7E]*$/'],
            'result' => 'nullable|in:success,unavailable,error',
            'source' => 'nullable|in:subscription,app',
            'status' => 'nullable|integer|min:100|max:599',
            'dimension' => 'nullable|in:ip,ua,user',
            'fresh' => 'nullable|in:0,1',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if (!$validator->errors()->isEmpty()) return;
            if ($this->filled('time_from') && $this->filled('time_to')
                && (int)$this->input('time_from') > (int)$this->input('time_to')) {
                $validator->errors()->add('time_to', '结束时间不能早于开始时间');
            }
        });
    }
}
