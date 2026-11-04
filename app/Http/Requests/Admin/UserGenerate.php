<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use App\Models\User;

class UserGenerate extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['email_prefix', 'email_suffix'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => User::normalizeEmail($this->input($field))]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'generate_count' => 'nullable|integer|max:500',
            'expired_at' => 'nullable|integer',
            'plan_id' => 'nullable|integer',
            'email_prefix' => 'nullable|string',
            'email_suffix' => ['bail', 'required', 'string', function ($attribute, $value, $fail) {
                $prefix = $this->input('email_prefix');
                if ($prefix !== null && !is_string($prefix)) return;
                $email = ($prefix ?: 'audit1') . '@' . $value;
                if (Validator::make(['email' => $email], [
                    'email' => 'required|string|email:strict|max:64|regex:/^[\x21-\x7E]+$/',
                ])->fails()) $fail('邮箱格式不正确');
            }],
            'password' => 'nullable'
        ];
    }

    public function messages()
    {
        return [
            'generate_count.integer' => '生成数量必须为数字',
            'generate_count.max' => '生成数量最大为500个'
        ];
    }
}
