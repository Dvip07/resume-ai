<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\JobListing;

class JobListingController extends Controller
{
    /**
     * Display a listing of the resource — a pure read of `job_listings`.
     *
     * Discovery used to happen right here: the method called Adzuna once per
     * suggested role and upserted the results before rendering, so every page
     * view paid for provider latency, and a slow or failing source degraded the
     * screen. That fan-out now belongs to the queued App\Jobs\DiscoverJobsForUser
     * (Requirement 11.1), dispatched by the scheduled `jobs:discover` command or
     * on demand via `POST /api/jobs/discover`, with
     * App\Services\JobSources\JobDiscoveryOrchestrator owning normalization,
     * deduplication and persistence.
     *
     * This screen therefore shows what discovery has already found. A user whose
     * profile was just parsed sees an empty list until the next run, which is the
     * intended trade: the read is fast and cannot fail on a third party.
     */
    public function index()
    {
        $jobsResult = JobListing::where('is_active', true)->orderBy('posted_at', 'desc')->get();

        return view('jobs.index', compact('jobsResult'));
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

    /**
     * Display the specified resource.
     *
     * This used to drive Chrome, in-request, on the first view of any posting
     * (`scrapeAndUpdateJobDescription()`), and `updateJobDescriptions()` next to
     * it did the same for one hard-coded `api_id` behind
     * `GET /update-job-descriptions`. Both are gone (task 9.3, Requirement 12.3):
     * description enrichment is App\Jobs\EnrichJobDescription, queued and
     * retryable, so a page view neither waits on a third-party site nor silently
     * fails when that site serves a bot wall. The screen renders whatever
     * description the pipeline has stored so far.
     */
    public function show(string $id)
    {
        $job = JobListing::where('api_id', $id)->first();

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
