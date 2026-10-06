<?php

namespace App\Services;

use App\Enums\ModelUsageOutcome;
use App\Exceptions\ModelRouterException;
use App\Models\ModelUsageLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single door every LLM call in the pipeline goes through
 * (Requirement 4.1). Feature code never talks to a model provider directly —
 * it hands over a task type, messages and a routing context, and gets back a
 * ModelCompletionResult or a ModelRouterException.
 *
 * Scope note: the transport (chat/completions call, structured output, JSON
 * extraction) landed in task 7.2, tier selection in task 7.3, same-tier
 * fallback retry in task 7.4 and usage/cost logging in task 7.5 — every
 * attempt, success or failure, writes a `model_usage_logs` row through
 * recordUsage() (Requirement 4.6).
 *
 * Everything configurable (models, tiers, thresholds, timeouts) is read from
 * config('services.openrouter') so tuning never needs a code change
 * (Requirement 4.2), and the API key comes from the environment only
 * (Requirement 4.7).
 */
class ModelRouterService
{
    private const COMPLETIONS_PATH = '/chat/completions';

    /**
     * Run a completion and return its content plus usage accounting.
     *
     * @param string                    $taskType   e.g. 'jd_fit_analysis', 'jd_rating',
     *                                              'resume_tailor', 'cover_letter_tailor'
     * @param array<int, array<string, mixed>> $messages OpenAI-style role/content messages
     * @param array<string, mixed>|null $jsonSchema when given, the call asks for
     *                                              structured output and the result's
     *                                              parsedJson is guaranteed populated
     * @param Model|null                $relatedTo  the record this call is about
     *                                              (job listing, resume, …), recorded
     *                                              polymorphically on the usage row
     *                                              (Requirement 4.6)
     *
     * Why `$relatedTo` is a trailing optional argument rather than a field on
     * ModelTierContext: the context object exists to answer "which tier?" and
     * is deliberately free of persistence concerns (it is constructed from raw
     * signals in `fromSignals()` and is unit-testable without a database).
     * The related record influences nothing about routing — it is purely
     * bookkeeping — so it stays out of the routing input. Being trailing and
     * optional, it also leaves every existing 3- and 4-argument caller working
     * unchanged; those calls simply log a usage row with a null relation.
     *
     * @param string|null $tierOverride pins the tier instead of deriving it
     *                                  from `$context`. For *capability*
     *                                  requirements only — see below.
     *
     * Why a tier override exists at all: tier selection is a cost/stakes
     * trade-off (Requirements 4.3/4.4), and nothing may bypass it just to buy a
     * better model. But some tasks need a model with a specific *capability*
     * rather than a specific quality — the LLM job-search source (task 8.5)
     * only works on a web-search-augmented model, and no salary or complexity
     * signal can express that. Those callers pin the tier that holds the
     * capable models; the tier and its models still come from
     * config('services.openrouter.tiers'), so Requirement 4.2 holds and an
     * unconfigured tier fails loudly rather than silently running a model that
     * can't do the job.
     *
     * @throws ModelRouterException on any unrecoverable failure
     */
    public function complete(
        string $taskType,
        array $messages,
        ModelTierContext $context,
        ?array $jsonSchema = null,
        ?Model $relatedTo = null,
        ?string $tierOverride = null,
    ): ModelCompletionResult {
        $apiKey = $this->config('api_key');

        // Nothing was sent, so there is no usage to record: these throw before
        // any attempt exists, and a configuration mistake is not a model call.
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw ModelRouterException::missingApiKey($taskType);
        }

        $tier = $tierOverride !== null && trim($tierOverride) !== ''
            ? trim($tierOverride)
            : $this->selectTier($context);

        // Throws unknownTier/noModelsConfigured for a pinned tier that isn't
        // configured, which is the intended behaviour: a capability the caller
        // requires is missing, so the call must not proceed on a lesser model.
        $models = $this->candidateModels($taskType, $tier);

        return $this->completeWithFallback(
            $taskType,
            $tier,
            $models,
            $messages,
            $jsonSchema,
            $apiKey,
            $relatedTo
        );
    }

    /**
     * Walk the tier's candidate models until one succeeds (Requirement 4.5).
     *
     * Why client-side iteration instead of OpenRouter's own `models: [...]`
     * server-side fallback: OpenRouter reports only the model that finally
     * answered, so a server-side fallback silently swallows the intermediate
     * failures. Requirement 4.5 wants each failure logged with model id, tier,
     * task type and error, and task 7.5's `model_usage_logs` wants a row per
     * call including failures. Iterating here is the only way to see the
     * attempts we are required to record.
     *
     * Shape of the walk:
     *   - each model gets up to `max_attempts_per_model` attempts, because a
     *     429/5xx is often gone a moment later and re-asking the preferred
     *     (usually cheaper/better-fit) model beats immediately downgrading;
     *   - a linear backoff separates attempts *on the same model* — there is
     *     no wait when switching models, since a different model means
     *     different capacity and waiting would only add latency;
     *   - a terminal failure (bad key, malformed request, no credits) aborts
     *     the whole walk immediately: every candidate would fail identically,
     *     so retrying just delays the inevitable.
     *
     * @param array<int, string>                $models
     * @param array<int, array<string, mixed>>  $messages
     * @param array<string, mixed>|null         $jsonSchema
     *
     * @throws ModelRouterException when every candidate is exhausted, carrying
     *                             the full attempt history
     */
    protected function completeWithFallback(
        string $taskType,
        string $tier,
        array $models,
        array $messages,
        ?array $jsonSchema,
        string $apiKey,
        ?Model $relatedTo = null,
    ): ModelCompletionResult {
        $maxAttempts = max(1, (int) $this->config('max_attempts_per_model', 2));
        $lastModelIndex = count($models) - 1;

        /** @var array<int, array<string, mixed>> $attempts */
        $attempts = [];
        $lastFailure = null;

        foreach ($models as $modelIndex => $model) {
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $response = $this->dispatch($taskType, $tier, $model, $messages, $jsonSchema, $apiKey);

                    $result = $this->toResult($taskType, $tier, $model, $response, $jsonSchema);

                    $this->recordUsage([
                        'task_type' => $taskType,
                        // What actually ran, which can differ from $model when
                        // OpenRouter re-routes (see toResult()).
                        'model_used' => $result->modelUsed,
                        'tier_used' => $tier,
                        'outcome' => ModelUsageOutcome::Success,
                        'attempt' => $attempt,
                        'input_tokens' => $result->inputTokens,
                        'output_tokens' => $result->outputTokens,
                        'estimated_cost_usd' => $result->estimatedCostUsd,
                        'related' => $relatedTo,
                    ]);

                    if ($attempts !== []) {
                        Log::info('OpenRouter call succeeded after fallback', [
                            'task_type' => $taskType,
                            'tier' => $tier,
                            'model' => $result->modelUsed,
                            'preferred_model' => $models[0],
                            'failed_attempts' => count($attempts),
                        ]);
                    }

                    return $result;
                } catch (ModelRouterException $e) {
                    $lastFailure = $e;

                    $attempts[] = $record = [
                        'task_type' => $taskType,
                        'tier' => $tier,
                        'model' => $model,
                        'model_index' => $modelIndex,
                        'attempt' => $attempt,
                        'status' => $e->context['status'] ?? null,
                        'retryable' => $e->isRetryable(),
                        'error' => $e->getMessage(),
                    ];

                    // One line per failed attempt with everything Req 4.5 asks
                    // for: model id, tier, task type, error.
                    Log::warning('OpenRouter call attempt failed', $record);

                    // A row per failed attempt too (Req 4.6 says every call).
                    // Tokens/cost stay 0: a failed attempt returned no usage
                    // block, and inventing a number would corrupt cost totals.
                    $this->recordUsage([
                        'task_type' => $taskType,
                        'model_used' => $model,
                        'tier_used' => $tier,
                        'outcome' => ModelUsageOutcome::Failure,
                        'attempt' => $attempt,
                        'http_status' => $e->context['status'] ?? null,
                        'error' => $e->getMessage(),
                        'related' => $relatedTo,
                    ]);

                    if (! $e->isRetryable()) {
                        Log::error('OpenRouter call failed terminally; skipping fallback models', $record + [
                            'skipped_models' => array_slice($models, $modelIndex + 1),
                        ]);

                        throw $e->withAttemptHistory($attempts);
                    }

                    if ($attempt < $maxAttempts) {
                        $this->backoff($attempt);
                    }
                }
            }

            if ($modelIndex < $lastModelIndex) {
                Log::warning('OpenRouter falling back to next model in tier', [
                    'task_type' => $taskType,
                    'tier' => $tier,
                    'from_model' => $model,
                    'to_model' => $models[$modelIndex + 1],
                    'attempts_so_far' => count($attempts),
                ]);
            }
        }

        Log::error('OpenRouter exhausted every candidate model in tier', [
            'task_type' => $taskType,
            'tier' => $tier,
            'models' => $models,
            'attempts' => $attempts,
        ]);

        throw ModelRouterException::allCandidatesFailed($taskType, $tier, $attempts, $lastFailure);
    }

    /**
     * Linear backoff between attempts on the same model.
     *
     * Linear rather than exponential on purpose: this sits inside a queued job
     * that already has its own retry/backoff, so the in-request wait only needs
     * to ride out a brief rate-limit blip, not implement a full retry policy.
     * Set `retry_delay_ms` to 0 to disable (tests do).
     */
    protected function backoff(int $attempt): void
    {
        $delayMs = (int) $this->config('retry_delay_ms', 250);

        if ($delayMs > 0) {
            usleep($delayMs * $attempt * 1000);
        }
    }

    /**
     * Which tier this piece of work belongs in (Requirements 4.3, 4.4).
     *
     * Two paths, never blended:
     *   - salary known → compare ModelTierContext::salaryBasis() against
     *     `salary_thresholds` (Req 4.3);
     *   - salary unknown → compare the 0-100 complexity score against
     *     `complexity_thresholds` (Req 4.4).
     *
     * In both cases the highest threshold the value clears wins, and a value
     * below every threshold stays at `default_tier`. Escalation is therefore
     * something a signal has to earn: an empty or unknown context can never
     * land on the most expensive tier (Req 4.4).
     *
     * No currency conversion happens here — see the note on ModelTierContext.
     */
    protected function selectTier(ModelTierContext $context): string
    {
        $default = (string) $this->config('default_tier', 'cheap');

        [$value, $thresholds] = $context->hasSalary()
            ? [$context->salaryBasis(), $this->config('salary_thresholds')]
            : [$context->complexityScore, $this->config('complexity_thresholds')];

        return $this->tierForValue($value, $thresholds, $default);
    }

    /**
     * Highest matching threshold wins; nothing matched means the default tier.
     *
     * Thresholds arrive straight from config as a tier => minimum map, so they
     * are sorted here rather than trusting the author to list them in order.
     * Non-numeric or malformed entries are ignored instead of throwing: a typo
     * in a threshold should degrade to the cheap default, not break every LLM
     * call in the pipeline.
     */
    protected function tierForValue(int $value, mixed $thresholds, string $default): string
    {
        if (! is_array($thresholds)) {
            return $default;
        }

        $ordered = [];

        foreach ($thresholds as $tier => $minimum) {
            if (is_string($tier) && $tier !== '' && is_numeric($minimum)) {
                $ordered[$tier] = (int) $minimum;
            }
        }

        // Descending, so the first clear match is the highest applicable tier.
        // Equal thresholds keep their config order (PHP sorts are stable).
        arsort($ordered);

        foreach ($ordered as $tier => $minimum) {
            if ($value >= $minimum) {
                return $tier;
            }
        }

        return $default;
    }

    /**
     * The ordered model list for a tier: index 0 preferred, the rest same-tier
     * fallbacks.
     *
     * completeWithFallback() walks this list in order on retryable
     * failure/timeout (Requirement 4.5).
     *
     * @return array<int, string>
     */
    protected function candidateModels(string $taskType, string $tier): array
    {
        $tiers = $this->config('tiers', []);

        if (! is_array($tiers) || ! array_key_exists($tier, $tiers)) {
            throw ModelRouterException::unknownTier($taskType, $tier);
        }

        $models = array_values(array_filter((array) $tiers[$tier], fn ($m) => is_string($m) && $m !== ''));

        if ($models === []) {
            throw ModelRouterException::noModelsConfigured($taskType, $tier);
        }

        return $models;
    }

    /**
     * Write one `model_usage_logs` row for one attempt — success or failure
     * (Requirement 4.6).
     *
     * Never allowed to break the pipeline: cost bookkeeping is strictly less
     * important than the work the LLM call is part of, so a failed insert
     * (migration not run, DB blip, oversized value) is logged and swallowed
     * instead of turning a perfectly good completion into a failed job.
     *
     * @param array<string, mixed> $attributes accepts `related` as an Eloquent
     *                                         model, which is flattened into
     *                                         related_type/related_id here so
     *                                         callers never touch morph keys
     */
    protected function recordUsage(array $attributes): void
    {
        try {
            $related = $attributes['related'] ?? null;
            unset($attributes['related']);

            $error = $attributes['error'] ?? null;
            unset($attributes['error']);

            ModelUsageLog::create($attributes + [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'estimated_cost_usd' => 0,
                'http_status' => null,
                // Bounded so a giant provider error body can never blow up the
                // insert; the full text is already in the log line above.
                'error' => is_string($error) ? Str::limit($error, 1000) : null,
                'related_type' => $related instanceof Model ? $related->getMorphClass() : null,
                'related_id' => $related instanceof Model ? $related->getKey() : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record OpenRouter usage', [
                'task_type' => $attributes['task_type'] ?? null,
                'model_used' => $attributes['model_used'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * One HTTP round trip to OpenRouter for one model.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>|null        $jsonSchema
     */
    protected function dispatch(
        string $taskType,
        string $tier,
        string $model,
        array $messages,
        ?array $jsonSchema,
        string $apiKey,
    ): Response {
        $payload = $this->buildPayload($model, $messages, $jsonSchema);

        try {
            $response = $this->request($apiKey)->post($this->completionsUrl(), $payload);
        } catch (ConnectionException $e) {
            // Timeouts and DNS/connection errors: typed so callers can mark
            // their stage failed with a reason (Requirement 4.5). Logging lives
            // in completeWithFallback() so each attempt is logged exactly once,
            // with its attempt number and position in the tier.
            throw ModelRouterException::transportFailure($taskType, $tier, $model, $e);
        }

        if (! $response->successful()) {
            throw ModelRouterException::httpFailure(
                $taskType,
                $tier,
                $model,
                $response->status(),
                $response->body()
            );
        }

        return $response;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>|null        $jsonSchema
     *
     * @return array<string, mixed>
     */
    protected function buildPayload(string $model, array $messages, ?array $jsonSchema): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            // Asks OpenRouter to return real token counts and its own cost
            // figure in `usage`, which is what the usage log records.
            'usage' => ['include' => true],
        ];

        if ($jsonSchema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => $this->normalizeJsonSchema($jsonSchema),
            ];
        }

        return $payload;
    }

    /**
     * Accept either a bare JSON Schema (`['type' => 'object', ...]`) or an
     * already-wrapped OpenRouter `json_schema` block, so callers can pass
     * whichever they have without the router guessing wrong.
     *
     * @param array<string, mixed> $jsonSchema
     *
     * @return array<string, mixed>
     */
    protected function normalizeJsonSchema(array $jsonSchema): array
    {
        if (array_key_exists('schema', $jsonSchema)) {
            return $jsonSchema + ['name' => 'response', 'strict' => true];
        }

        return [
            'name' => 'response',
            'strict' => true,
            'schema' => $jsonSchema,
        ];
    }

    /**
     * @param array<string, mixed>|null $jsonSchema
     */
    protected function toResult(
        string $taskType,
        string $tier,
        string $model,
        Response $response,
        ?array $jsonSchema,
    ): ModelCompletionResult {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $content = Arr::get($body, 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw ModelRouterException::emptyCompletion($taskType, $tier, $model, $response->body());
        }

        $parsedJson = [];

        if ($jsonSchema !== null) {
            $parsedJson = $this->extractJson($content);

            if ($parsedJson === null) {
                throw ModelRouterException::unparseableJson($taskType, $tier, $model, $content);
            }
        }

        // A model that ignores our `model` field (OpenRouter can re-route) is
        // reported back as what actually ran, so the usage log stays honest.
        $modelUsed = Arr::get($body, 'model');
        $modelUsed = is_string($modelUsed) && $modelUsed !== '' ? $modelUsed : $model;

        return new ModelCompletionResult(
            content: $content,
            parsedJson: $parsedJson,
            modelUsed: $modelUsed,
            tierUsed: $tier,
            inputTokens: (int) Arr::get($body, 'usage.prompt_tokens', 0),
            outputTokens: (int) Arr::get($body, 'usage.completion_tokens', 0),
            estimatedCostUsd: (float) Arr::get($body, 'usage.cost', 0),
        );
    }

    /**
     * Pull a JSON object out of a completion, tolerating models that wrap it
     * in prose or a markdown fence.
     *
     * Same three-step approach previously proven in AnalyzeResumeJob's own
     * Ollama-response extractor (since retired in favour of this one): strict
     * decode first (what a structured-output-capable model returns), then a
     * ```json fence, then the outermost bare `{...}`. Returns null when
     * nothing decodes, so the caller decides whether that's fatal.
     *
     * @return array<mixed>|null
     */
    public function extractJson(string $content): ?array
    {
        $candidates = [trim($content)];

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $content, $matches)) {
            $candidates[] = $matches[1];
        }

        if (preg_match('/(\{.*\})/s', $content, $matches)) {
            $candidates[] = $matches[1];
        }

        if (preg_match('/(\[.*\])/s', $content, $matches)) {
            $candidates[] = $matches[1];
        }

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $decoded = json_decode($candidate, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    protected function request(string $apiKey): PendingRequest
    {
        $headers = array_filter([
            // OpenRouter attribution headers; both are optional and only
            // affect its own reporting.
            'HTTP-Referer' => $this->stringConfig('referer'),
            'X-Title' => $this->stringConfig('title'),
        ], fn ($value) => $value !== null);

        return Http::withToken($apiKey)
            ->withHeaders($headers)
            ->acceptJson()
            ->connectTimeout((int) $this->config('connect_timeout', 10))
            ->timeout((int) $this->config('timeout', 120));
    }

    protected function completionsUrl(): string
    {
        $base = rtrim((string) $this->config('base_url', 'https://openrouter.ai/api/v1'), '/');

        return $base . self::COMPLETIONS_PATH;
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("services.openrouter.{$key}", $default);
    }

    private function stringConfig(string $key): ?string
    {
        $value = $this->config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
