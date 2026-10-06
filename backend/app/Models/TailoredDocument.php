<?php

namespace App\Models;

use App\Enums\TailoredDocumentStatus;
use App\Enums\TailoredDocumentType;
use App\Services\Tailoring\FabricationReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One generated document — a tailored resume or a cover letter — for one
 * (user, job listing) pair (Requirements 6.3, 6.5, 6.6).
 *
 * Backed by the repurposed legacy `tailored_resumes` table (see
 * 2026_09_17_115133_rename_tailored_resumes_to_tailored_documents_table), which
 * is why the model name and the table name don't share a stem and `$table` is
 * set explicitly rather than inferred.
 *
 * A row is created `pending` before the LaTeX compile runs and moves to
 * `rendered` once the PDF is in S3, so a crashed render leaves a record instead
 * of nothing. `template_key`, `generation_model` and `generation_tier` record
 * how the document was produced (Requirement 6.3) so a result can be reviewed
 * or reproduced without re-inferring it.
 *
 * @property int $user_id
 * @property int $job_listing_id
 * @property int|null $application_id
 * @property TailoredDocumentType $type
 * @property string $template_key
 * @property string $s3_path
 * @property string|null $tex_source_s3_path
 * @property string $generation_model
 * @property string $generation_tier
 * @property array<string, mixed>|null $fabrication_flags
 * @property TailoredDocumentStatus $status
 */
class TailoredDocument extends Model
{
    use HasFactory;

    protected $table = 'tailored_documents';

    protected $fillable = [
        'user_id',
        'job_listing_id',
        'application_id',
        'type',
        'template_key',
        's3_path',
        'tex_source_s3_path',
        'generation_model',
        'generation_tier',
        'fabrication_flags',
        'status',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'job_listing_id' => 'integer',
        'application_id' => 'integer',
        'type' => TailoredDocumentType::class,
        // Plain `array`, so FabricationReport::flags() round-trips as-is:
        // its nested `findings` list and scalar metadata keys come back
        // exactly as they went in, with no shape imposed here.
        'fabrication_flags' => 'array',
        'status' => TailoredDocumentStatus::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jobListing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class);
    }

    /** Null until the document is linked to the application it's used for. */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** Documents of one kind, e.g. `->ofType(TailoredDocumentType::Resume)`. */
    public function scopeOfType(Builder $query, TailoredDocumentType $type): Builder
    {
        return $query->where('type', $type);
    }

    /** Successfully rendered documents only — the ones with a usable `s3_path`. */
    public function scopeRendered(Builder $query): Builder
    {
        return $query->where('status', TailoredDocumentStatus::Rendered);
    }

    /**
     * Store a fabrication inspection's result on this row (Requirement 6.6).
     * Acting on it — moving the run to `needs_review` — stays with the caller,
     * for the reasons in {@see FabricationReport}.
     */
    public function recordFabricationReport(FabricationReport $report): self
    {
        $this->fabrication_flags = $report->flags();

        return $this;
    }

    /** Is this document safe to attach to an application submission? */
    public function isUsable(): bool
    {
        return $this->status->isUsable() && $this->s3_path !== '';
    }
}
