<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJobsRequest;
use App\Http\Requests\UpdateJobsRequest;
use App\Models\Jobs;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class JobsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    // public function index()
    // {
    //     return view('jobs.index');
    // }

    // public function fetchJobs()
    // {
    //     $client = new Client();

    //     $response = $client->request('GET', 'https://linkedin-job-api.p.rapidapi.com/job/search', [
    //         'query' => [
    //             'keyword' => 'Software developer',
    //             'location' => 'Oshawa, Ontario'
    //         ],
    //         'headers' => [
    //             'X-RapidAPI-Key' => env('RAPIDAPI_KEY'),  // Use .env for security
    //             'X-RapidAPI-Host' => 'linkedin-job-api.p.rapidapi.com'
    //         ]
    //     ]);

    //     $jobs = json_decode($response->getBody(), true);

    //     return view('jobs.index', compact('jobs'));
    // }


    // public function fetchJobs()
    // {

    //     $client = new Client();

    //     $apiKey = config('services.rapidapi.key');

    //     dd([
    //         'env_direct' => env('RAPIDAPI_KEY'),
    //         'config_services' => config('services.rapidapi.key')
    //     ]);


    //     // $response = $client->request('GET', 'https://linkedin-job-api.p.rapidapi.com/job/search', [
    //     //     'query' => [
    //     //         'keyword' => 'Software developer',
    //     //         'location' => 'Oshawa, Ontario',
    //     //     ],
    //     //     'headers' => [
    //     //         'X-RapidAPI-Key' => $apiKey,
    //     //         'X-RapidAPI-Host' => 'linkedin-job-api.p.rapidapi.com'
    //     //     ]
    //     // ]);

    //     try {
    //         $response = $client->request('GET', 'https://linkedin-job-api.p.rapidapi.com/job/search', [
    //             'query' => [
    //                 'keyword' => 'Software developer',
    //                 'location' => 'Oshawa, Ontario',
    //             ],
    //             'headers' => [
    //                 'X-RapidAPI-Key' => config('services.rapidapi.key'),
    //                 'X-RapidAPI-Host' => 'linkedin-job-api.p.rapidapi.com'
    //             ]
    //         ]);

    //         $body = json_decode($response->getBody(), true);

    //         dd($body); // <-- Check exactly what API returns

    //     } catch (\Exception $e) {
    //         dd($e->getMessage());
    //     }


    //     // $jobs = json_decode($response->getBody(), true);

    //     // return view('jobs.index', compact('jobs'));
    // }

    // public function index()
    // {
    //     $client = new Client();

    //     try {
    //         $response = $client->request('GET', 'https://linkedin-job-api.p.rapidapi.com/job/search', [
    //             'query' => [
    //                 'keyword' => 'Developer', // broader keyword
    //                 'location' => 'Toronto',  // broader or nearby city
    //                 'limit' => 20             // fetch more jobs to be sure
    //             ],
    //             'headers' => [
    //                 'X-RapidAPI-Key' => config('services.rapidapi.key'),
    //                 'X-RapidAPI-Host' => 'linkedin-job-api.p.rapidapi.com'
    //             ]
    //         ]);

    //         $jobs = json_decode($response->getBody(), true);

    //     } catch (\Exception $e) {
    //         \Log::error('Job API Error: ' . $e->getMessage());
    //         $jobs = ['data' => []];
    //     }

    //     return view('jobs.index', compact('jobs'));
    // }



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
    public function store(StoreJobsRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Jobs $jobs)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Jobs $jobs)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateJobsRequest $request, Jobs $jobs)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Jobs $jobs)
    {
        //
    }
}
