<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use App\Models\JobListing;
use App\Models\UserProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Panther\Client as PantherClient;
use Symfony\Component\Panther\PantherTestCase;
use App\Jobs\UpdateJobDescription;
use Facebook\WebDriver\Chrome\ChromeOptions;
use DOMDocument;
use DOMXPath;


class JobListingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {

        $userId = auth()->id();
        $userProfile = UserProfile::where('user_id', $userId)->first();

        // Convert location JSON (cast as an array) to a text string
        $locationData = $userProfile->location; // e.g. ["city" => "Oshawa", "state" => "Ontario", "country" => "Canada"]
        $locationParts = [];
        if (!empty($locationData['city'])) {
            $locationParts[] = $locationData['city'];
        }
        if (!empty($locationData['state'])) {
            $locationParts[] = $locationData['state'];
        }
        if (!empty($locationData['country'])) {
            $locationParts[] = $locationData['country'];
        }
        $locationString = implode(', ', $locationParts);

        // Convert suggested_roles JSON (cast as an array) to a text string
        $suggestedRolesArray = $userProfile->suggested_roles; // e.g. ["Software Engineer", "Lead Developer", "Project Manager"]
        $suggestedRolesString = explode(', ', $suggestedRolesArray) ?: '';

        $client = new Client();
        $resultsAdzuna = [];
        // $resultsLinkedIn = [];

        // foreach ($suggestedRolesString as $role) {
        //     try {
        //         $response = $client->request('GET', 'https://linkedin-job-api.p.rapidapi.com/job/search', [
        //             'query' => [
        //                 'keyword' => $role,
        //                 'location' => $locationString, // e.g. "Oshawa, Ontario, Canada"
        //                 'dateSincePosted' => 'Past Week',
        //                 'jobType' => 'Full Time',
        //                 'experienceLevel' => 'Entry Level',
        //                 'limit' => 10,
        //                 'sortBy' => 'Recent',
        //                 'page' => 1,
        //             ],
        //             'headers' => [
        //                 'X-RapidAPI-Key' => config('services.rapidapi.key'),
        //                 'X-RapidAPI-Host' => 'linkedin-job-api.p.rapidapi.com'
        //             ]

        //         ]);
        //         // dd($response);
        //         $body = $response->getBody();
        //         $data = json_decode($body, true);
        //         \Log::info('Job API Response: ' . $body);

        //         // Check if there is a message or error field in the response
        //         if (isset($data['error'])) {
        //             \Log::error('Job API returned error: ' . $data['error']);
        //         }

        //         $results[$role] = json_decode($response->getBody(), true);
        //     } catch (\Exception $e) {
        //         \Log::error('Job API Error for role ' . $role . ': ' . $e->getMessage());
        //         $results[$role] = ['data' => []];
        //     }
        // }
        // dd($results);

        $locationCountry = $locationData['country'];

        // Fetch jobs from Adzuna API for each suggested role
        // Uncomment this one
        foreach ($suggestedRolesString as $role) {
            try {
                $response = $client->request('GET', 'https://api.adzuna.com/v1/api/jobs/ca/search/1', [
                    'query' => [
                        'app_id' => config('services.adzuna.app_id'),
                        'app_key' => config('services.adzuna.app_key'),
                        'results_per_page' => 5,
                        'what' => $role,          // Use the role as the search keyword
                        'location0' => $locationCountry, // e.g. "Canada" or other location string
                        'max_days_old' => 30,
                    ]
                ]);

                $body = $response->getBody();
                $data = json_decode($body, true);

                // dd($data); 
                // if (isset($data['results'])) {
                //     foreach ($data['results'] as $job) {

                //         $jobUrl = $job['redirect_url'];

                //         // Check if the job already exists
                //         $existingJob = JobListing::where('api_id', $job['id'])->first();
                //         if (!$existingJob) {
                //             // Use Panther to scrape the full job description
                //             $panther = PantherClient::createChromeClient();
                //             $crawler = $panther->request('GET', $jobUrl);

                //             // Adjust the selector based on the actual structure of the job posting page
                //             $fullDescription = $crawler->filter('.job-description')->text();
                //             Log::info('Full Description: ' . $fullDescription);
                //             // $resultsAdzuna = $fullDescription;
                //             // dd($resultsAdzuna);


                //             // dd($job['redirect_url']);
                //             // Check if the job already exists
                //             // $existingJob = JobListing::where('api_id', $job['id'])->first();
                //             // if (!$existingJob) {
                //             JobListing::create([
                //                 'api_id' => $job['id'],
                //                 'title' => $job['title'],
                //                 'company' => $job['company']['display_name'] ?? 'Unknown',
                //                 'location' => $job['location']['display_name'] ?? 'Unknown',
                //                 'description' => $fullDescription ?? $job['description'],
                //                 'api_source' => 'adzuna',
                //                 'posted_at' => Carbon::parse($job['created']),
                //                 'is_active' => true,
                //                 'parsed_skills' => Null,
                //                 'application_url' => $job['redirect_url'],
                //             ]);
                //             \Log::info('Last Query Executed: ', DB::getQueryLog());
                //             $panther->close();
                //         }
                //     }
                // }

                if (isset($data['results'])) {
                    foreach ($data['results'] as $job) {

                        $jobUrl = $job['redirect_url'];

                        // Check if the job already exists
                        $existingJob = JobListing::where('api_id', $job['id'])->first();

                        $jobData = [
                            'api_id' => $job['id'],
                            'title' => $job['title'],
                            'company' => $job['company']['display_name'] ?? 'Unknown',
                            'location' => $job['location']['display_name'] ?? 'Unknown',
                            'description' => $job['description'] ?? 'No description provided',
                            'api_source' => 'adzuna',
                            'posted_at' => Carbon::parse($job['created']),
                            'is_active' => true,
                            'parsed_skills' => null,
                            'application_url' => $job['redirect_url'],
                        ];

                        if ($existingJob) {
                            // Update the existing job
                            $existingJob->update($jobData);
                            Log::info('Updated existing job with api_id: ' . $job['id']);
                        } else {
                            // Create a new job
                            JobListing::create($jobData);
                            Log::info('Created new job with api_id: ' . $job['id']);
                        }

                        // $jobId = $jobData['id'];
                        // $jobDesc = JobListing::where('api_id', $jobId)->first();

                        // try {
                        //     \Log::info("Updating description for job ID {$job->id} from URL: {$job->application_url}");

                        //     // Specify the path to your ChromeDriver binary.
                        //     $chromeDriverPath = base_path('drivers/chromedriver');

                        //     // Create a Panther client with the specified ChromeDriver path.
                        //     $panther = PantherClient::createChromeClient(null, null, [], $chromeDriverPath);
                        //     $crawler = $panther->request('GET', $job->application_url);

                        //     // Extract the description using an appropriate selector.
                        //     $fullDescription = $crawler->filter('.job-description')->text();

                        //     // Update the job description in the database.
                        //     $job->update(['description' => $fullDescription]);

                        //     \Log::info("Updated description for job ID {$job->id}");

                        //     $panther->close();
                        // } catch (\Exception $e) {
                        //     \Log::error("Failed to update description for job ID {$job->id}: " . $e->getMessage());
                        // }

                        // Log::info("Dispatching update job for ID: " . $jobData['id'] . " with URL: " . $jobUrl);
                        // dispatch(new UpdateJobDescription($jobData['id'], $jobUrl));                                             
                    }
                }
                Log::info('Adzuna API Response for role ' . $role . ': ' . $body);


                \Log::info('Adzuna API Response for role ' . $role . ': ' . $body);

                if (isset($data['error'])) {
                    \Log::error('Adzuna API returned error for role ' . $role . ': ' . $data['error']);
                }

                $resultsAdzuna[$role] = $data;
            } catch (\Exception $e) {
                \Log::error('Adzuna API Error for role ' . $role . ': ' . $e->getMessage());
                $resultsAdzuna[$role] = ['data' => []];
            }
        }
        // Till this


        // dd($resultsAdzuna);

        // Fetch jobs from LinkedIn API via RapidAPI for each suggested role
        // foreach ($suggestedRolesString as $role) {
        //     try {
        //         $response = $client->request('GET', 'https://linkedin-job-api.p.rapidapi.com/job/search', [
        //             'query' => [
        //                 'keyword' => $role,
        //                 'location' => $locationString, 
        //                 'dateSincePosted' => 'Past Week',
        //                 'jobType' => 'Full Time',
        //                 'experienceLevel' => 'Entry Level',
        //                 'limit' => 10,
        //                 'sortBy' => 'Recent',
        //                 'page' => 1,
        //             ],
        //             'headers' => [
        //                 'X-RapidAPI-Key' => config('services.rapidapi.key'),
        //                 'X-RapidAPI-Host' => 'linkedin-job-api.p.rapidapi.com'
        //             ]
        //         ]);

        //         $statusCode = $response->getStatusCode();
        //         \Log::info("LinkedIn API Status Code: $statusCode");

        //         if ($statusCode !== 200) {
        //             \Log::error("Unexpected API response: " . $response->getBody());
        //         }

        //         $body = $response->getBody();
        //         $data = json_decode($body, true);
        //         \Log::info('LinkedIn API Response for role ' . $role . ': ' . $body);

        //         if (isset($data['error'])) {
        //             \Log::error('LinkedIn API returned error for role ' . $role . ': ' . $data['error']);
        //         }

        //         $resultsLinkedIn[$role] = $data;

        //     } catch (\Exception $e) {
        //         \Log::error('LinkedIn API Error for role ' . $role . ': ' . $e->getMessage());
        //         $resultsLinkedIn[$role] = ['data' => []];
        //     }

        // }
        // dd($resultsLinkedIn);

        $jobsResult = JobListing::where('is_active', true)->orderBy('posted_at', 'desc')->get();
        // dd($jobsResult);

        return view('jobs.index', compact('resultsAdzuna', 'jobsResult'));
    }


    public function updateJobDescriptions()
    {
        // Fetch jobs that need an updated description.
        // JobListing::chunk(5, function ($jobs) {
        //     foreach ($jobs as $job) {
        //         try {
        //             \Log::info("Updating description for job ID {$job->id} from URL: {$job->application_url}");

        //             // Specify the path to your ChromeDriver binary.
        //             $chromeDriverPath = base_path('drivers/chromedriver');

        //             // Create a Panther client with the specified ChromeDriver path.
        //             $panther = PantherClient::createChromeClient(null, null, [], $chromeDriverPath);
        //             $crawler = $panther->request('GET', $job->application_url);

        //             // Extract the description using an appropriate selector.
        //             $fullDescription = $crawler->filter('.job-description')->text();

        //             // Update the job description in the database.
        //             $job->update(['description' => $fullDescription]);

        //             \Log::info("Updated description for job ID {$job->id}");

        //             $panther->close();
        //         } catch (\Exception $e) {
        //             \Log::error("Failed to update description for job ID {$job->id}: " . $e->getMessage());
        //         }
        //     }
        // });

        $jobId = 5053158833;
        $jobDesc = JobListing::where('api_id', $jobId)->first();

        try {
            \Log::info("Updating description for job ID {$jobId} from URL: {$jobDesc->application_url}");

            // Specify the path to your ChromeDriver binary.
            $chromeDriverPath = base_path('drivers/chromedriver');

            // Create a Panther client with the specified ChromeDriver path.
            $panther = PantherClient::createChromeClient(null, null, [], $chromeDriverPath);
            $crawler = $panther->request('GET', $jobDesc->application_url);

            // Extract the description using an appropriate selector.
            $fullDescription = $crawler->filter('.job-description')->text();

            // Update the job description in the database.
            $jobDesc->update(['description' => $fullDescription]);

            \Log::info("Updated description for job ID {$jobDesc->id}");

            $panther->close();
        } catch (\Exception $e) {
            \Log::error("Failed to update description for job ID {$jobDesc->id}: " . $e->getMessage());
        }

        return view('resumes.create', compact($jobDesc));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    private function scrapeAndUpdateJobDescription($job)
    {
        try {
            \Log::info("Scraping description for job ID {$job->api_id} from URL: {$job->application_url}");

            // Create the client with minimal configuration
            $panther = PantherClient::createChromeClient(null, [
                '--window-size=1920,1080',
                '--disable-blink-features=AutomationControlled',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--user-agent=Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Safari/537.36'
            ]);

            // Add more human-like behavior
            $panther->manage()->timeouts()->pageLoadTimeout(30);
            sleep(rand(3, 8)); // Random delay before visit

            // Navigate to the URL
            $crawler = $panther->request('GET', $job->application_url);

            // Simulate scrolling
            $panther->executeScript('window.scrollTo(0, 300);');
            sleep(1);
            $panther->executeScript('window.scrollTo(0, 600);');
            sleep(2);

            // Wait for JS content to load
            $panther->wait(10)->until(
                function () use ($panther) {
                    return $panther->executeScript('return document.readyState === "complete"');
                }
            );
            

            $pageTitle = $panther->getTitle();
            \Log::info("Page title: " . $pageTitle);

            // Check if we hit an Access Denied page
            if (
                stripos($pageTitle, 'Access Denied') !== false ||
                stripos($pageTitle, 'Blocked') !== false
            ) {
                \Log::error("Access denied or blocked by the website");

                // Try to take a screenshot to see what's happening (optional)
                $screenshotPath = storage_path('logs/screenshot_' . $job->api_id . '.png');
                $panther->takeScreenshot($screenshotPath);
                \Log::info("Saved screenshot to $screenshotPath");

                $panther->close();
                return;
            }

            // Try all the class selectors you provided
            $selectors = [
                '.show-more-less-html__markup',
                '.show-more-less-html__markup--clamp-after-5
            relative',
                '.job-description',
                '.jobs-box__html-content',
                '.show-more-less-html__markup', // Corrected class from page source
                '.t-14.t-normal.jobs-description-content__text--stretch',
                '[data-automation="jobDescription"]',
                '.job-details-description',
                '#job-description',
                '.description',
                '[itemprop="description"]'
            ];            

            $fullDescription = "";
            foreach ($selectors as $selector) {
                try {
                    if ($crawler->filter($selector)->count() > 0) {
                        $fullDescription = $crawler->filter($selector)->html();
                        \Log::info("Found content with selector: $selector");
                        break;
                    }
                } catch (\Exception $e) {
                    continue; // Try next selector
                }
            }

            if (empty($fullDescription)) {
                try {
                    $fullDescription = $panther->getPageSource();
                    \Log::warning("Using full page source as fallback for job ID {$job->api_id}");
                    \Log::info("HTML snippet: " . substr($fullDescription, 0, 500));
                } catch (\Exception $e) {
                    \Log::error("Failed to retrieve page source: " . $e->getMessage());
                }
            }

           // Assume $fullDescription contains the complete HTML source
            $dom = new DOMDocument();
            // Suppress warnings for malformed HTML
            libxml_use_internal_errors(true);
            $dom->loadHTML($fullDescription);
            libxml_clear_errors();

            // Create an XPath instance to query the document
            $xpath = new DOMXPath($dom);

            // This XPath query finds the <div> that is a descendant of a <section>
            // with class "show-more-less-html" and has the class "show-more-less-html__markup"
            $nodes = $xpath->query("//section[contains(@class, 'show-more-less-html')]//div[contains(@class, 'show-more-less-html__markup')]");

            if ($nodes->length > 0) {
                // Extract the inner text (or use ->C14N() for full HTML)
                $jobDescription = trim($nodes->item(0)->textContent);
                \Log::info("Extracted job description snippet: " . substr($jobDescription, 0, 30000));
                // Now update your job record with $jobDescription, for example:
                // $job->update(['description' => $jobDescription]);
            } else {
                \Log::error("No job description found using the new selector.");
            }
            // dd($fullDescription);

    
            // Update the job record if description is extracted
            if (!empty($jobDescription)) {
                $job->update(['description' => $jobDescription]);
                \Log::info("Successfully updated job ID {$job->id} with new description.");
            } else {
                \Log::error("No description found for job ID {$job->api_id}");
            }

            $panther->close();
        } catch (\Exception $e) {
            \Log::error("Failed to scrape description for job ID {$job->api_id}: " . $e->getMessage());
            \Log::error($e->getTraceAsString());
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // dd($id);

        $job = JobListing::where('api_id', $id)->first();
        // dd($job);



        if($job->created_at == $job->updated_at) {
            $this->scrapeAndUpdateJobDescription($job);
        }

        // dd($jobListing);
        return view('jobs.show', compact('job'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
