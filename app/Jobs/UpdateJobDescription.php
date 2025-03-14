<?php

namespace App\Jobs;

use App\Models\JobListing;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Panther\Client as PantherClient;

class UpdateJobDescription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $jobListingId;
    protected $jobUrl;

    /**
     * Create a new job instance.
     */
    public function __construct($jobListingId, $jobUrl)
    {
        $this->jobListingId = $jobListingId;
        $this->jobUrl = $jobUrl;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $jobListing = JobListing::find($this->jobListingId);
        if (!$jobListing) {
            Log::error("Job listing with ID {$this->jobListingId} not found.");
            return;
        }

        try {
            Log::info("Updating description for job ID {$this->jobListingId} from URL: {$this->jobUrl}");
            $chromeDriverPath = base_path('drivers/chromedriver');
            // Create Panther client with the specified chromedriver binary path
            $panther = PantherClient::createChromeClient(null, null, [], $chromeDriverPath);
            $crawler = $panther->request('GET', $this->jobUrl);

            // Adjust the selector based on your job posting page structure
            $fullDescription = $crawler->filter('.job-description')->text();
            Log::info("Successfully retrieved description for job ID {$this->jobListingId}");

            $panther->close();
        } catch (\Exception $e) {
            Log::error("Error updating job description for ID {$this->jobListingId}: " . $e->getMessage());
            $fullDescription = null;
        }

        if ($fullDescription) {
            $jobListing->update(['description' => $fullDescription]);
            Log::info("Job description updated for job ID {$this->jobListingId}");
        }
    }
}
