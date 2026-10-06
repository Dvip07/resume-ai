<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserProfile;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON API surface for the authenticated user's profile (Sanctum
 * bearer-token auth).
 *
 * Profiles are normally created/updated by AnalyzeResumeJob after a resume
 * upload (see App\Jobs\AnalyzeResumeJob::updateUserProfile), but automatic
 * extraction can be imperfect, so this controller lets the frontend read
 * and manually correct any field.
 *
 * Validates: Requirements 2.5
 */
class ProfileController extends Controller
{
    /** Keys allowed inside the `skills` object when the nested shape is used. */
    private const SKILL_GROUPS = ['primary', 'secondary'];

    /** Keys allowed inside the `location` object. */
    private const LOCATION_KEYS = ['city', 'state', 'country'];

    /** Keys allowed on each `experience` entry. */
    private const EXPERIENCE_KEYS = ['company', 'position', 'duration', 'achievements'];

    /** Keys allowed on each `education` entry. */
    private const EDUCATION_KEYS = ['institution', 'degree', 'fieldOfStudy', 'startYear', 'endYear'];

    /** URL columns, which are NOT NULL in the schema (see the migration). */
    private const URL_FIELDS = ['linkedin_url', 'github_url', 'portfolio_url'];

    /**
     * Return the authenticated user's profile.
     *
     * A user may not have a profile yet (e.g. before their first resume is
     * parsed) — this is not an error, so a 404 is returned rather than a
     * 500/empty-model response the frontend would have to special-case.
     */
    public function show(Request $request): JsonResponse
    {
        $profile = UserProfile::where('user_id', $request->user()->id)->first();

        if (! $profile) {
            return response()->json([
                'message' => 'Profile not found.',
            ], 404);
        }

        return response()->json($profile);
    }

    /**
     * Validate and persist manual edits to the authenticated user's
     * profile. Creates the profile if one doesn't exist yet, since a user
     * may want to fill it in manually before ever uploading a resume.
     *
     * The underlying `user_profiles` columns are NOT NULL with no database
     * defaults (see the original migration), so when creating a brand new
     * profile any field not supplied by the request is backfilled with an
     * empty value rather than leaving it to a database error.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules($request), $this->messages());

        // `''` arrives as null courtesy of Laravel's ConvertEmptyStringsToNull
        // middleware, but the URL columns are NOT NULL — so "cleared by the
        // user" is persisted as an empty string, matching what AnalyzeResumeJob
        // writes when it finds no link.
        foreach (self::URL_FIELDS as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] === null) {
                $validated[$field] = '';
            }
        }

        $profile = UserProfile::firstOrNew(['user_id' => $request->user()->id]);

        if (! $profile->exists) {
            $profile->fill(array_merge([
                'skills' => [],
                'location' => [],
                'linkedin_url' => '',
                'github_url' => '',
                'portfolio_url' => '',
                'suggested_roles' => [],
                'experience' => [],
                'education' => [],
                'parsed_keywords' => '',
                'resume_text' => '',
            ], $validated));
        } else {
            $profile->fill($validated);
        }

        $profile->save();

        return response()->json($profile);
    }

    /**
     * Validation rules for a profile patch.
     *
     * These deliberately mirror the shapes AnalyzeResumeJob actually persists
     * (see its `updateUserProfile`), so the frontend can round-trip an
     * auto-extracted profile back through this endpoint unchanged:
     *
     * - `skills`     — either `{ primary: string[], secondary: string[] }` (what
     *                  the parser writes) or a flat `string[]` (a manually
     *                  entered, ungrouped list)
     * - `location`   — `{ city, state, country }`
     * - `experience` — `[{ company, position, duration, achievements: string[] }]`
     * - `education`  — `[{ institution, degree, fieldOfStudy, startYear, endYear }]`
     *
     * Every shape is enumerated key by key and unknown keys are rejected, so
     * this stays a real contract rather than an "any JSON goes" passthrough.
     *
     * @return array<string, mixed>
     */
    private function rules(Request $request): array
    {
        $rules = [
            'location' => ['sometimes', 'array', $this->onlyKeys(self::LOCATION_KEYS, 'location')],
            'linkedin_url' => ['sometimes', 'nullable', 'string', 'url', 'max:255'],
            'github_url' => ['sometimes', 'nullable', 'string', 'url', 'max:255'],
            'portfolio_url' => ['sometimes', 'nullable', 'string', 'url', 'max:255'],
            'suggested_roles' => ['sometimes', 'array'],
            'suggested_roles.*' => ['nullable', 'string', 'max:255'],

            'experience' => ['sometimes', 'array'],
            'experience.*' => ['array', $this->onlyKeys(self::EXPERIENCE_KEYS, 'experience entry')],
            'experience.*.company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'experience.*.position' => ['sometimes', 'nullable', 'string', 'max:255'],
            // The parser writes a month count (int); a hand-edited entry may
            // say "2 years" — both are accepted, arrays/objects are not.
            'experience.*.duration' => ['sometimes', 'nullable', $this->scalarText('duration')],
            'experience.*.achievements' => ['sometimes', 'array'],
            'experience.*.achievements.*' => ['nullable', 'string', 'max:2000'],

            'education' => ['sometimes', 'array'],
            'education.*' => ['array', $this->onlyKeys(self::EDUCATION_KEYS, 'education entry')],
            'education.*.institution' => ['sometimes', 'nullable', 'string', 'max:255'],
            'education.*.degree' => ['sometimes', 'nullable', 'string', 'max:255'],
            'education.*.fieldOfStudy' => ['sometimes', 'nullable', 'string', 'max:255'],
            'education.*.startYear' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2200'],
            'education.*.endYear' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2200'],
        ];

        foreach ($this->skillsRules($request) as $key => $rule) {
            $rules[$key] = $rule;
        }

        return $rules;
    }

    /**
     * `skills` has two legitimate shapes, and Laravel can't express "either"
     * in one rule set, so the applicable branch is chosen from the payload:
     * a nested object keyed by skill group, or a flat list of strings.
     *
     * @return array<string, mixed>
     */
    private function skillsRules(Request $request): array
    {
        $skills = $request->input('skills');

        if (! $request->has('skills') || ! is_array($skills)) {
            // Non-array (or absent) input falls through to the base rule so a
            // string payload still reports a plain "must be an array" error.
            return ['skills' => ['sometimes', 'array']];
        }

        $isNested = ! array_is_list($skills);

        if (! $isNested) {
            return [
                'skills' => ['array'],
                'skills.*' => ['nullable', 'string', 'max:255'],
            ];
        }

        $rules = [
            'skills' => ['array', $this->onlyKeys(self::SKILL_GROUPS, 'skills')],
        ];

        foreach (self::SKILL_GROUPS as $group) {
            $rules["skills.$group"] = ['sometimes', 'array'];
            $rules["skills.$group.*"] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /**
     * Rule rejecting any key outside `$allowed`, so a typo'd or unexpected
     * field is reported instead of silently persisted into the JSON column.
     *
     * @param  array<int, string>  $allowed
     */
    private function onlyKeys(array $allowed, string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowed, $label): void {
            if (! is_array($value)) {
                return;
            }

            // A positional list (e.g. `["Toronto"]` for location) is a shape
            // error, not an unknown-key error — say so plainly.
            if ($value !== [] && array_is_list($value)) {
                $fail(sprintf(
                    'The %s must be an object with named fields: %s.',
                    $label,
                    implode(', ', $allowed),
                ));

                return;
            }

            $unknown = array_diff(array_keys($value), $allowed);

            if ($unknown !== []) {
                $fail(sprintf(
                    'The %s contains unsupported field(s): %s. Allowed: %s.',
                    $label,
                    implode(', ', array_map('strval', $unknown)),
                    implode(', ', $allowed),
                ));
            }
        };
    }

    /** Rule accepting a string or a number, but not an array/object/bool. */
    private function scalarText(string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($label): void {
            if (is_string($value) && mb_strlen($value) <= 255) {
                return;
            }

            if (is_int($value) || is_float($value)) {
                return;
            }

            $fail("The $label must be text or a number.");
        };
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'experience.*.array' => 'Each experience entry must be an object.',
            'education.*.array' => 'Each education entry must be an object.',
        ];
    }
}
