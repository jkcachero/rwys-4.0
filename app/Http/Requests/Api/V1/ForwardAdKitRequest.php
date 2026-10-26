<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use JsonException;

class ForwardAdKitRequest extends FormRequest
{
    private const INSIGHT_FIELDS = 'account_id,account_currency,campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,impressions,reach,clicks,spend,cpc,cpm,ctr,date_start,date_stop';

    private const ACCOUNT_FIELDS = 'id,account_id,name,account_status,disable_reason,currency,timezone_name';

    private const OBJECT_FIELDS = 'id,account_id,name,status,effective_status,objective,daily_budget,lifetime_budget,created_time,updated_time,campaign_id,adset_id';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::in(['meta'])],
            'endpoint' => ['required', 'string', 'max:100', 'regex:~\A/?act_[0-9]+(?:/(?:campaigns|insights|adsets|ads))?\z~'],
            'accountId' => ['sometimes', 'string', 'max:100', 'regex:/\Aact_[0-9]+\z/'],
            'method' => ['required', Rule::in(['GET'])],
            'description' => ['required', 'string', 'max:200'],
            'payload' => ['required', 'array:fields,time_range,date_preset,level,limit,after'],
            'payload.fields' => ['required', 'string', 'max:1000', 'regex:/\A[a-z_]+(?:,[a-z_]+)*\z/'],
            'payload.time_range' => ['sometimes', 'string', 'max:100'],
            'payload.date_preset' => ['sometimes', 'string', Rule::in([
                'maximum', 'today', 'yesterday', 'this_month', 'last_month', 'this_quarter', 'last_quarter',
                'this_year', 'last_year', 'last_3d', 'last_7d', 'last_14d', 'last_28d', 'last_30d', 'last_90d',
                'this_week_mon_today', 'this_week_sun_today', 'last_week_mon_sun', 'last_week_sun_sat',
            ])],
            'payload.level' => ['sometimes', Rule::in(['account', 'campaign', 'adset', 'ad'])],
            'payload.limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'payload.after' => ['sometimes', 'string', 'regex:~\A[A-Za-z0-9_+=/\-]{1,4096}\z~'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->query() !== []) {
                $validator->errors()->add('query', 'Query parameters are not allowed.');
            }
            foreach (array_diff(array_keys($this->all()), ['platform', 'endpoint', 'accountId', 'method', 'description', 'payload']) as $field) {
                $validator->errors()->add($field, 'The field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $insights = str_ends_with($this->input('endpoint'), '/insights');
            $fields = explode(',', $this->input('payload.fields'));
            $allowed = $insights ? self::INSIGHT_FIELDS
                : (str_contains(ltrim($this->input('endpoint'), '/'), '/') ? self::OBJECT_FIELDS : self::ACCOUNT_FIELDS);
            if (array_diff($fields, explode(',', $allowed)) !== [] || count($fields) !== count(array_unique($fields))) {
                $validator->errors()->add('payload.fields', 'Only supported scalar fields are allowed.');
            }
            $payload = $this->input('payload');
            $hasRange = array_key_exists('time_range', $payload);
            $hasPreset = array_key_exists('date_preset', $payload);
            if (($insights && $hasRange === $hasPreset)
                || (! $insights && ($hasRange || $hasPreset || array_key_exists('level', $payload)))) {
                $validator->errors()->add('payload', 'Insights require exactly one time_range or date_preset; other reads cannot specify insight parameters.');
            }
            if (! $hasRange) {
                return;
            }
            try {
                $range = json_decode($payload['time_range'], true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $range = null;
            }
            if (! is_array($range) || count($range) !== 2 || array_diff(array_keys($range), ['since', 'until']) !== []
                || ValidatorFacade::make($range, [
                    'since' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                    'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:since', 'before_or_equal:today'],
                ])->fails()
                || Carbon::parse($range['since'])->diffInDays(Carbon::parse($range['until'])) >= 90) {
                $validator->errors()->add('payload.time_range', 'The time_range must encode valid since and until dates, no future dates, and at most 90 inclusive days.');
            }
        }];
    }
}
