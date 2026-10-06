<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Exceptions\ModelRouterException;
use App\Models\Resume;
use App\Models\UserProfile;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;

/**
 * Extracts structured profile data from an uploaded resume (Requirement 2.2).
 *
 * The extraction runs through ModelRouterService with task type
 * `resume_parse` (task 7.7), so this job no longer talks to any model provider
 * itself: tier selection, same-tier fallback, structured output and usage/cost
 * logging all live in the router. The local `ollama.py` Flask sidecar it used
 * to call is no longer referenced from application code (Requirement 12.3).
 *
 * Status contract (Requirement 2.4): the resume arrives here as `parsing`
 * and MUST leave in a terminal state — `parsed` on success, `failed` with a
 * user-facing `status_error` on any failure (router error, unparseable
 * output, thrown exception, or the job dying/exhausting retries via
 * `failed()`). It must never be left silently stuck in `parsing`.
 */
class AnalyzeResumeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TASK_TYPE = 'resume_parse';

    private $resume;
    private $resumeText;

    public function __construct(Resume $resume, string $resumeText)
    {
        $this->resume = $resume;
        $this->resumeText = $resumeText;
    }

    public function handle(ModelRouterService $router)
    {
        try {
            $result = $router->complete(
                self::TASK_TYPE,
                $this->messages(),
                // No salary signal exists for a resume, so routing falls to the
                // complexity heuristic; resume length is the only difficulty
                // signal available here (a long, dense resume is more work to
                // extract from than a one-pager).
                ModelTierContext::fromSignals(jdLength: mb_strlen($this->resumeText)),
                $this->extractionSchema(),
                // Attributes the usage/cost row to this resume (Requirement 4.6).
                $this->resume,
            );

            $parsedData = $result->parsedJson;

            // An empty result means the model produced structurally valid but
            // empty output — treated as a failure rather than a successful
            // parse with nothing in it (Requirement 2.4).
            if ($parsedData === []) {
                $this->markFailed(
                    'The resume analysis service returned no readable structured data. Please try uploading again.'
                );

                return;
            }

            // Update `user_profiles` table with extracted data
            $this->updateUserProfile($parsedData);

            // Update `resumes` table with parsed data
            $this->resume->update([
                'parsed_data' => $parsedData,
            ]);

            $this->resume->markParsed();

            Log::info("Resume analysis completed for Resume ID: " . $this->resume->id, [
                'model_used' => $result->modelUsed,
                'tier_used' => $result->tierUsed,
            ]);
        } catch (ModelRouterException $e) {
            // The router already logged every attempt; store a reason the user
            // can act on and leave the resume terminal (Requirement 2.4).
            Log::error('Resume analysis model call failed: ' . $e->getMessage(), [
                'resume_id' => $this->resume->getKey(),
                'context' => $e->context,
            ]);

            $this->markFailed('Resume analysis failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            Log::error("Resume Analysis Failed: " . $e->getMessage());

            $this->markFailed('Resume analysis failed: ' . $e->getMessage());
        }
    }

    /**
     * The extraction prompt, as chat messages for the router.
     *
     * @return array<int, array<string, string>>
     */
    private function messages(): array
    {
        return [
            [
                'role' => 'system',
                'content' => 'You extract structured profile data from resumes. '
                    . 'Respond with JSON matching the requested schema only, using empty '
                    . 'strings or empty arrays for details the resume does not contain. '
                    . 'Never invent facts that are not in the resume text.',
            ],
            [
                'role' => 'user',
                'content' => "Extract key details and analyze the resume. Return the following details in JSON format:\n\n"
                    . "1. Skills in a 'skills' object with 'primary' and 'secondary' arrays.\n"
                    . "2. Work experience in a 'workExperience' array with objects containing: 'companyName', 'position', 'duration' (in months), and 'achievements' array.\n"
                    . "3. Education details in an 'education' object with 'institution' array, 'degree' array, 'fieldOfStudy' array, and 'yearsAttended' array of objects with 'startYear' and 'endYear'.\n"
                    . "4. A 'keywords' array with relevant terms from the resume.\n"
                    . "5. A 'summary' field with a 2-3 sentence professional summary.\n"
                    . "6. A 'suggestedJobRoles' array with relevant job roles based on the resume.\n"
                    . "7. A 'location' object with keys 'city', 'state', and 'country' if available.\n"
                    . "8. 'linkedin_url', 'github_url', and 'portfolio_url' as separate fields if available.\n\n"
                    . "Resume Text:\n" . $this->resumeText,
            ],
        ];
    }

    /**
     * Structured-output schema for the extraction.
     *
     * Deliberately mirrors the shape `updateUserProfile()` already normalizes:
     * `education` stays as parallel arrays (institution/degree/fieldOfStudy/
     * yearsAttended) because that is what the mapper reads, and every object
     * lists all its properties as required with `additionalProperties: false`
     * so the router's strict structured-output mode is satisfied. Fields the
     * resume doesn't contain come back empty rather than absent, which the
     * mapper's `?? ''` / `?? []` defaults already handle.
     *
     * @return array<string, mixed>
     */
    private function extractionSchema(): array
    {
        $stringList = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'skills', 'workExperience', 'education', 'keywords', 'summary',
                'suggestedJobRoles', 'location', 'linkedin_url', 'github_url', 'portfolio_url',
            ],
            'properties' => [
                'skills' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['primary', 'secondary'],
                    'properties' => [
                        'primary' => $stringList,
                        'secondary' => $stringList,
                    ],
                ],
                'workExperience' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['companyName', 'position', 'duration', 'achievements'],
                        'properties' => [
                            'companyName' => ['type' => 'string'],
                            'position' => ['type' => 'string'],
                            'duration' => ['type' => 'integer', 'description' => 'Total months in the role.'],
                            'achievements' => $stringList,
                        ],
                    ],
                ],
                'education' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['institution', 'degree', 'fieldOfStudy', 'yearsAttended'],
                    'properties' => [
                        'institution' => $stringList,
                        'degree' => $stringList,
                        'fieldOfStudy' => $stringList,
                        // Positionally aligned with the arrays above: index i of
                        // each array describes the same qualification.
                        'yearsAttended' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['startYear', 'endYear'],
                                'properties' => [
                                    'startYear' => ['type' => ['integer', 'null']],
                                    'endYear' => ['type' => ['integer', 'null']],
                                ],
                            ],
                        ],
                    ],
                ],
                'keywords' => $stringList,
                'summary' => ['type' => 'string'],
                'suggestedJobRoles' => $stringList,
                'location' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['city', 'state', 'country'],
                    'properties' => [
                        'city' => ['type' => 'string'],
                        'state' => ['type' => 'string'],
                        'country' => ['type' => 'string'],
                    ],
                ],
                'linkedin_url' => ['type' => 'string'],
                'github_url' => ['type' => 'string'],
                'portfolio_url' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Called by the queue when the job dies outright (uncaught throwable,
     * retries exhausted, timeout). Without this a resume whose job never
     * reaches the `catch` above would stay in `parsing` forever
     * (Requirement 2.4).
     */
    public function failed(?\Throwable $exception): void
    {
        $this->markFailed(
            'Resume analysis failed: '
            . ($exception ? $exception->getMessage() : 'the analysis job did not complete.')
        );
    }

    /**
     * Mark the resume failed, tolerating a row that has since been deleted
     * (and never letting a bookkeeping error mask the original failure).
     */
    private function markFailed(string $reason): void
    {
        try {
            $resume = Resume::find($this->resume->getKey());

            // Don't clobber an already-terminal state: if handle() already
            // recorded a specific reason, failed() shouldn't overwrite it.
            if ($resume && ! $resume->status?->isTerminal()) {
                $resume->markFailed($reason);
            }
        } catch (\Throwable $e) {
            Log::error('Could not mark resume as failed: ' . $e->getMessage(), [
                'resume_id' => $this->resume->getKey(),
            ]);
        }
    }

    private function updateUserProfile(array $parsedData): void
    {
        try {
            // Log the entire parsed data for debugging
            Log::info('Parsed data: ' . json_encode($parsedData));

            // Normalize and extract skills data
            if (isset($parsedData['skills'])) {
                $normalizedSkills = array_change_key_case($parsedData['skills'], CASE_LOWER);
                $skills = [
                    'primary' => $normalizedSkills['primary'] ?? [],
                    'secondary' => $normalizedSkills['secondary'] ?? []
                ];
                Log::info('Using skills data from $parsedData[\'skills\']: ' . json_encode($normalizedSkills));
            } elseif (isset($parsedData['Skills'])) {
                $normalizedSkills = array_change_key_case($parsedData['Skills'], CASE_LOWER);
                $skills = [
                    'primary' => $normalizedSkills['primary'] ?? [],
                    'secondary' => $normalizedSkills['secondary'] ?? []
                ];
                Log::info('Using skills data from $parsedData[\'Skills\']: ' . json_encode($normalizedSkills));
            } else {
                Log::warning('No structured skills data found in parsed data');
                $skills = ['primary' => [], 'secondary' => []];
            }

            // Handle work experience
            $experience = [];
            if (isset($parsedData['workExperience'])) {
                foreach ($parsedData['workExperience'] as $work) {
                    // If duration is provided as an array (e.g. {"months": 12}), extract the months
                    $duration = $work['duration'] ?? 0;
                    if (is_array($duration) && isset($duration['months'])) {
                        $duration = $duration['months'];
                    }
                    $experience[] = [
                        'company' => $work['companyName'] ?? '',
                        'position' => $work['position'] ?? '',
                        'duration' => $duration,
                        'achievements' => $work['achievements'] ?? []
                    ];
                }
            } elseif (isset($parsedData['experience'])) {
                $experience = $parsedData['experience'];
            }

            // Map education data
            $education = [];
            if (isset($parsedData['education'])) {
                if (isset($parsedData['education']['institution'])) {
                    for ($i = 0; $i < count($parsedData['education']['institution'] ?? []); $i++) {
                        $education[] = [
                            'institution' => $parsedData['education']['institution'][$i] ?? '',
                            'degree' => $parsedData['education']['degree'][$i] ?? '',
                            'fieldOfStudy' => $parsedData['education']['fieldOfStudy'][$i] ?? '',
                            'startYear' => $parsedData['education']['yearsAttended'][$i]['startYear'] ?? null,
                            'endYear' => $parsedData['education']['yearsAttended'][$i]['endYear'] ?? null
                        ];
                    }
                } elseif (is_array($parsedData['education'])) {
                    $education = $parsedData['education'];
                }
            }

            // Extract suggested job roles (assuming the key is 'suggestedJobRoles' in the response)
            $suggestedRoles = $parsedData['suggestedJobRoles'] ?? [];

            // Extract additional fields with safe defaults:
            $location = $parsedData['location'] ?? [];
            $linkedinUrl = $parsedData['linkedin_url'] ?? '';
            $githubUrl = $parsedData['github_url'] ?? '';
            $portfolioUrl = $parsedData['portfolio_url'] ?? '';

            // Update or create the user profile with all fields
            UserProfile::updateOrCreate(
                ['user_id' => $this->resume->user_id],
                [
                    'skills' => $skills,
                    'location' => $location,
                    'linkedin_url' => $linkedinUrl,
                    'github_url' => $githubUrl,
                    'portfolio_url' => $portfolioUrl,
                    'suggested_roles' => $suggestedRoles,
                    'experience' => $experience,
                    'education' => $education,
                    'parsed_keywords' => implode(', ', ($parsedData['keywords'] ?? [])),
                    'resume_text' => $this->resumeText,
                ]
            );

            Log::info('User profile updated successfully for user_id: ' . $this->resume->user_id);
        } catch (\Exception $e) {
            Log::error('Failed to update user profile: ' . $e->getMessage(), [
                'exception' => $e,
                'parsedData' => json_encode($parsedData)
            ]);
        }
    }
}
