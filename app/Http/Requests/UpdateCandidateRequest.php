<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCandidateRequest extends FormRequest
{
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
        return [
            'name' => 'required|string|max:250|min:3',
            'dob' => 'required|date',
            'address' => 'required|string|max:250|min:3',
            'phone' => 'required|numeric|regex:/(0)[0-9]{9}/',
        ];
    }
}
