<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\JobListing;
use Symfony\Component\Panther\Client as PantherClient;
use Illuminate\Support\Facades\Log;
use Spatie\Async\Pool;

class UpdateJobDescriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jobs:update-descriptions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape and update job descriptions from job URLs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Retrieve all job listings that need an updated description.
        // $jobs = JobListing::whereNull('description')
        //     ->orWhere('description', 'like', '%initial description%')
        //     ->get();

        $jobs = JobListing::get();

        // Create a pool with concurrency of 3 (3 jobs run concurrently)
        $pool = Pool::create()->concurrency(3);

        foreach ($jobs as $job) {
            // For each job, add an asynchronous task to the pool.
            $pool[] = async(function () use ($job) {
                // Specify the path to your ChromeDriver binary.
                $chromeDriverPath = base_path('drivers/chromedriver');

                // Create a Panther client with the specified ChromeDriver path.
                $panther = PantherClient::createChromeClient(null, null, [], $chromeDriverPath);
                $crawler = $panther->request('GET', $job->application_url);

                // Adjust the selector based on your page structure.
                $fullDescription = $crawler->filter('.job-description')->text();

                $panther->close();

                // Return an array with job id and the fetched description.
                return ['id' => $job->id, 'description' => $fullDescription];
            })->then(function ($result) {
                // Retrieve the job by its id and update the description.
                $jobListing = JobListing::find($result['id']);
                if ($jobListing) {
                    $jobListing->update(['description' => $result['description']]);
                    Log::info("Updated description for job ID {$result['id']}");
                }
            })->catch(function (\Throwable $exception) use ($job) {
                Log::error("Failed to update description for job ID {$job->id}: " . $exception->getMessage());
            });
        }

        // Wait for all tasks in the pool to finish.
        $pool->wait();
    }
}
