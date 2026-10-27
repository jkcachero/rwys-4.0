<?php

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use JsonException;
use stdClass;

class ForwardAdKitRequest extends FormRequest
{
    private const INSIGHT_FIELDS = 'account_id,account_currency,campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,impressions,reach,clicks,spend,cpc,cpm,ctr,date_start,date_stop';

    private const ACCOUNT_FIELDS = 'id,account_id,name,account_status,disable_reason,currency,timezone_name';

    private const OBJECT_FIELDS = 'id,account_id,name,status,effective_status,objective,daily_budget,lifetime_budget,created_time,updated_time,campaign_id,adset_id,page_id';

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $write = $this->input('method') === 'POST';
        $rules = [
            'platform' => ['required', Rule::in(['meta'])],
            'endpoint' => ['required', 'string', 'max:100', $write
                ? 'regex:~\A/?act_[0-9]+/(?:campaigns|adsets|ads|adcreatives|adimages)\z~'
                : 'regex:~\A/?(?:act_[0-9]+(?:/(?:campaigns|insights|adsets|ads|adcreatives|adimages))?|[0-9]+(?:/(?:adsets|ads|insights))?)\z~'],
            'accountId' => ['sometimes', 'string', 'max:100', 'regex:/\Aact_[0-9]+\z/'],
            'method' => ['required', Rule::in(['GET', 'POST'])],
            'description' => ['required', 'string', 'max:200'],
        ];
        if ($write) {
            return [...$rules, ...$this->writeRules()];
        }

        return [...$rules,
            'payload' => ['required', 'array:fields,time_range,date_preset,level,limit,after,hashes'],
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
            'payload.hashes' => ['sometimes', 'string', 'max:10000', function (string $attribute, mixed $value, Closure $fail): void {
                $hashes = is_string($value) ? json_decode($value) : null;
                if (! is_array($hashes) || count($hashes) < 1 || count($hashes) > 100) {
                    $fail('The hashes must encode a list of image hashes.');

                    return;
                }
                foreach ($hashes as $hash) {
                    if (! is_string($hash) || preg_match('/\A[a-fA-F0-9]{32}\z/', $hash) !== 1) {
                        $fail('The hashes must encode a list of image hashes.');

                        return;
                    }
                }
            }],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $write = $this->input('method') === 'POST';
            $query = $this->query();
            if ((! $write && $query !== []) || ($write && (array_diff(array_keys($query), ['publish']) !== []
                || (array_key_exists('publish', $query) && ! in_array($query['publish'], [true, false, 'true', 'false'], true))))) {
                $validator->errors()->add('query', 'Only POST requests may specify publish=true or publish=false.');
            }
            foreach (array_diff(array_keys($this->validationData()), ['platform', 'endpoint', 'accountId', 'method', 'description', 'payload']) as $field) {
                $validator->errors()->add($field, 'The field is not allowed.');
            }
            if ($write) {
                $key = $this->header('Idempotency-Key');
                if (! is_string($key) || strlen($key) < 8 || strlen($key) > 200 || preg_match('/[\x00-\x1f\x7f]/', $key)) {
                    $validator->errors()->add('Idempotency-Key', 'An Idempotency-Key header of 8 to 200 characters is required.');
                }

                return;
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $insights = str_ends_with($this->input('endpoint'), '/insights');
            $fields = explode(',', $this->input('payload.fields'));
            $endpoint = ltrim($this->input('endpoint'), '/');
            $numeric = ctype_digit(explode('/', $endpoint)[0]);
            if ($numeric && ! $this->filled('accountId')) {
                $validator->errors()->add('accountId', 'Numeric object reads require the target accountId.');
            }
            $images = str_ends_with($endpoint, '/adimages');
            $allowed = $insights ? self::INSIGHT_FIELDS : ($images ? 'id,account_id,hash,name,url,width,height,original_width,original_height,status'
                : (($numeric || str_contains($endpoint, '/')) ? self::OBJECT_FIELDS : self::ACCOUNT_FIELDS));
            if (array_diff($fields, explode(',', $allowed)) !== [] || count($fields) !== count(array_unique($fields))) {
                $validator->errors()->add('payload.fields', 'Only supported scalar fields are allowed.');
            }
            $payload = $this->input('payload');
            if (! $images && array_key_exists('hashes', $payload)) {
                $validator->errors()->add('payload.hashes', 'Only adimages reads may specify hashes.');
            }
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

    /** @return array<string, array<mixed>> */
    private function writeRules(): array
    {
        $text = ['required', 'string', 'max:255'];
        $enum = ['required', 'string', 'max:100', 'regex:/\A[A-Z][A-Z0-9_]*\z/'];
        $id = ['required', $this->numericId()];
        $status = ['required', Rule::in(['ACTIVE', 'PAUSED', 'DELETED', 'ARCHIVED'])];
        $budget = ['sometimes', function (string $attribute, mixed $value, Closure $fail): void {
            if ((! is_int($value) && ! is_string($value)) || preg_match('/\A[0-9]+\z/', (string) $value) !== 1 || trim((string) $value, '0') === '') {
                $fail('The budget must be a positive integer in minor units.');
            }
        }];
        $edge = is_string($this->input('endpoint')) ? basename($this->input('endpoint')) : '';
        $fields = match ($edge) {
            'campaigns' => ['name' => $text, 'objective' => $enum, 'status' => $status,
                'daily_budget' => $budget, 'lifetime_budget' => $budget, 'bid_strategy' => ['sometimes', ...array_slice($enum, 1)],
                'special_ad_categories' => ['required', 'string', $this->jsonShape('categories')]],
            'adsets' => ['campaign_id' => $id, 'name' => $text, 'targeting' => ['required', 'string', 'max:65536', $this->jsonShape('targeting')],
                'billing_event' => $enum, 'optimization_goal' => $enum, 'promoted_object' => ['required', 'string', 'max:4096', $this->jsonShape('promoted')],
                'status' => $status, 'daily_budget' => $budget, 'lifetime_budget' => $budget,
                'bid_strategy' => ['sometimes', ...array_slice($enum, 1)], 'bid_amount' => $budget,
                'start_time' => ['sometimes', 'string', 'date', 'max:100'], 'end_time' => ['sometimes', 'string', 'date', 'max:100'],
                'is_dynamic_creative' => ['sometimes', 'boolean:strict']],
            'ads' => ['adset_id' => $id, 'creative' => ['required', 'string', 'max:4096', $this->jsonShape('creative')], 'name' => $text, 'status' => $status],
            'adcreatives' => ['name' => $text, 'object_story_spec' => ['required', 'string', 'max:65536', $this->jsonShape('story')]],
            'adimages' => ['bytes' => ['required', 'string', 'max:2796204', function (string $attribute, mixed $value, Closure $fail): void {
                $bytes = is_string($value) && strlen($value) <= 2796204 ? base64_decode($value, true) : false;
                $image = $bytes !== false && strlen($bytes) <= 2097152 ? @getimagesizefromstring($bytes) : false;
                if ($bytes === false || base64_encode($bytes) !== $value || $image === false
                    || ! in_array($image[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
                    || ($image[2] === IMAGETYPE_PNG && (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || ! str_ends_with($bytes, "\x00\x00\x00\x00IEND\xae\x42\x60\x82")))
                    || ($image[2] === IMAGETYPE_JPEG && (! str_starts_with($bytes, "\xff\xd8") || ! str_ends_with($bytes, "\xff\xd9")))
                    || $image[0] < 1 || $image[1] < 1 || $image[0] > 8192 || $image[1] > 8192 || $image[0] * $image[1] > 16777216) {
                    $fail('The bytes must encode a JPEG or PNG of at most 2 MB and bounded dimensions.');
                }
            }]],
            default => [],
        };
        $rules = ['payload' => ['required', 'array:'.implode(',', array_keys($fields))]];
        foreach ($fields as $field => $ruleset) {
            $rules['payload.'.$field] = $ruleset;
        }

        return $rules;
    }

    private function jsonShape(string $shape): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($shape): void {
            try {
                $decoded = is_string($value) ? json_decode($value, false, 32, JSON_THROW_ON_ERROR) : null;
            } catch (JsonException) {
                $decoded = null;
            }
            if ($shape === 'categories') {
                if (! is_array($decoded) || count($decoded) > 20 || array_filter($decoded, fn (mixed $item): bool => ! is_string($item) || preg_match('/\A[A-Z_]{1,100}\z/', $item) !== 1) !== []) {
                    $fail('The special_ad_categories must encode an array of category strings.');
                }

                return;
            }
            if (! $decoded instanceof stdClass) {
                $fail('The field must encode a supported JSON object.');

                return;
            }
            $data = json_decode($value, true);
            $id = ['required', $this->numericId()];
            $rules = match ($shape) {
                'creative' => ['root' => ['array:creative_id'], 'root.creative_id' => $id],
                'promoted' => ['root' => ['array:page_id,pixel_id,custom_event_type,application_id'], 'root.page_id' => $id,
                    'root.pixel_id' => ['sometimes', $this->numericId()], 'root.application_id' => ['sometimes', $this->numericId()], 'root.custom_event_type' => ['sometimes', 'string', 'max:100']],
                'story' => ['root' => ['array:page_id,instagram_user_id,photo_data,link_data'], 'root.page_id' => $id,
                    'root.instagram_user_id' => ['sometimes', $this->numericId()],
                    'root.photo_data' => ['required_without:root.link_data', 'prohibits:root.link_data', 'array:image_hash,caption'],
                    'root.photo_data.image_hash' => ['required_with:root.photo_data', 'string', 'regex:/\A[a-fA-F0-9]{32}\z/'],
                    'root.photo_data.caption' => ['sometimes', 'string', 'max:10000'],
                    'root.link_data' => ['required_without:root.photo_data', 'array:image_hash,link,message,name,description,call_to_action'],
                    'root.link_data.image_hash' => ['required_with:root.link_data', 'string', 'regex:/\A[a-fA-F0-9]{32}\z/'],
                    'root.link_data.link' => ['required_with:root.link_data', 'url:https', 'max:2048'],
                    'root.link_data.message' => ['sometimes', 'string', 'max:10000'], 'root.link_data.name' => ['sometimes', 'string', 'max:255'],
                    'root.link_data.description' => ['sometimes', 'string', 'max:1000'],
                    'root.link_data.call_to_action' => ['sometimes', 'array:type,value'],
                    'root.link_data.call_to_action.type' => ['required_with:root.link_data.call_to_action', 'string', 'max:100'],
                    'root.link_data.call_to_action.value' => ['sometimes', 'array:link'],
                    'root.link_data.call_to_action.value.link' => ['required_with:root.link_data.call_to_action.value', 'url:https', 'max:2048']],
                default => [],
            };
            if (($shape === 'story' && ((isset($decoded->photo_data) && ! $decoded->photo_data instanceof stdClass)
                || (isset($decoded->link_data) && ! $decoded->link_data instanceof stdClass)
                || (isset($decoded->link_data->call_to_action) && ! $decoded->link_data->call_to_action instanceof stdClass)
                || (isset($decoded->link_data->call_to_action->value) && ! $decoded->link_data->call_to_action->value instanceof stdClass)))
                || ValidatorFacade::make(['root' => $data], $rules)->fails()) {
                $fail('The field must encode a supported JSON object.');
            }
        };
    }

    private function numericId(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ((! is_string($value) && ! is_int($value)) || preg_match('/\A[0-9]+\z/', (string) $value) !== 1) {
                $fail('The ID must be a numeric string or integer.');
            }
        };
    }
}
