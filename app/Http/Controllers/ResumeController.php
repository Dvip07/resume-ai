<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreResumeRequest;
use App\Http\Requests\UpdateResumeRequest;
use App\Models\Resume;
use Spatie\PdfToText\Pdf;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Arr;
use App\Jobs\AnalyzeResumeJob;

class ResumeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('dashboard.view-profile');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('resumes.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    // public function store(StoreResumeRequest $request)
    // {
    //     // Validate the incoming request
    //     // $validated = $request->validated();

    //     // Handle the uploaded file
    //     $resumeFile = $request->file('resume');
    //     $originalFileName = $resumeFile->getClientOriginalName();
    //     $filePath = $resumeFile->storeAs('resumes', $originalFileName, 'public');

    //     // Extract text from the PDF file
    //     try {
    //         $pdf = new Pdf();
    //         $text = $pdf->setPdf(storage_path('app/public/' . $filePath))->text();
    //         // dd($text);
    //     } catch (\Exception $e) {
    //         return redirect()->back()->with('error', 'Error in extracting text from resume.');
    //     }

    //     // Send the extracted text to Ollama (Flask API) for analysis
    //     $analysisResult = $this->analyzeResumeWithOllama($text);

    //     // Save resume details along with analysis result
    //     try {
    //         $resume = new Resume();
    //         $resume->user_id = auth()->id();
    //         $resume->original_filename = $originalFileName;
    //         $resume->file_path = $filePath;

    //         // Use null coalescing operator to provide default values if analysis result is empty
    //         $resume->parsed_data = [
    //             'name' => Arr::get($analysisResult, 'name'),
    //             'email' => Arr::get($analysisResult, 'email'),
    //             'phone' => Arr::get($analysisResult, 'phone'),
    //             'skills' => Arr::get($analysisResult, 'skills', []),
    //             'experience' => Arr::get($analysisResult, 'experience', []),
    //             'education' => Arr::get($analysisResult, 'education', []),
    //             'certifications' => Arr::get($analysisResult, 'certifications', []),
    //             'projects' => Arr::get($analysisResult, 'projects', []),
    //             'summary' => Arr::get($analysisResult, 'summary'),
    //             'linkedin' => Arr::get($analysisResult, 'linkedin'),
    //             'github' => Arr::get($analysisResult, 'github'),
    //         ];

    //         $resume->job_analysis = Arr::get($analysisResult, 'job_analysis', []);
    //         $resume->ats_score = Arr::get($analysisResult, 'job_analysis.ats_score');
    //         $resume->is_optimized = false; // Default value; adjust based on your logic
    //         $resume->resume = $text; // Store the extracted text.
    //         $resume->save();

    //         return redirect()->route('resumes.index')->with('success', 'Resume uploaded and analyzed successfully.');
    //     } catch (\Exception $e) {
    //         \Log::error('Error saving resume data: ' . $e->getMessage());
    //         return redirect()->back()->with('error', 'Error in saving data: ' . $e->getMessage());
    //     }

    //     // Return success and redirect to resume listing page


    // }

    public function store(StoreResumeRequest $request)
    {
        $resumeFile = $request->file('resume');
        $originalFileName = $resumeFile->getClientOriginalName();
        $filePath = $resumeFile->storeAs('resumes', $originalFileName, 'public');

        try {
            $pdf = new Pdf();
            $resumeText = $pdf->setPdf(storage_path('app/public/' . $filePath))->text();
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error extracting text from resume.');
        }

        // Save initial resume record
        $resume = Resume::create([
            'user_id' => auth()->id(),
            'original_filename' => $originalFileName,
            'file_path' => $filePath,
            'resume' => $resumeText,
            'is_optimized' => false, // Initial state
        ]);

        // Dispatch the analysis job to the queue
        AnalyzeResumeJob::dispatch($resume, $resumeText);
        // dd($resumeText);
    

        return redirect()->route('resumes.index')->with('success', 'Resume uploaded. Analysis is in progress.');
    }

    // public function analyzeResumeWithOllama($resumeText)
    // {
    //     // Define the URL of your Flask app where Ollama is running
    //     $ollamaUrl = 'http://localhost:5001/llm'; // Flask server URL (where Ollama is running)

    //     try {
    //         // Define the shortened prompt for Ollama
    //         $prompt = "
    //         You are an AI specialized in analyzing resumes for job applications.
    //         Perform the following tasks:

    //         1. Extract key fields like:
    //            - Name, Email, Phone, Skills, Experience, Education, Certifications, Projects, Summary, LinkedIn Profile, GitHub Profile.

    //         2. Provide job analysis:
    //            - Identify strengths, recommend job roles, ATS score, and improvement suggestions.
    //         ";

    //         // Split resume text into smaller chunks (e.g., 1000 characters each) to avoid overwhelming the model
    //         $chunks = str_split($resumeText, 500); // Adjust chunk size as necessary

    //         $allResponses = [];

    //         // Process each chunk individually
    //         foreach ($chunks as $chunk) {
    //             try {
    //                 $response = Http::timeout(120) // Increase timeout to 2 minutes
    //                     ->post($ollamaUrl, [
    //                         'text' => $prompt . "\n\nResume Text:\n" . $chunk, // Concatenate prompt and chunked resume text
    //                     ]);

    //                 // Check if the request was successful
    //                 if ($response->successful()) {
    //                     $allResponses[] = $response->json(); // Collect responses for each chunk
    //                 } else {
    //                     // Log the error
    //                     \Log::error('Ollama API Error: ' . $response->status() . ' - ' . $response->body());
    //                     return response()->json(['error' => 'Failed to get analysis from Ollama for one of the chunks'], 500);
    //                 }
    //             } catch (\Exception $e) {
    //                 // Log the error
    //                 \Log::error('HTTP Exception: ' . $e->getMessage());
    //                 return response()->json(['error' => 'Error contacting Ollama API: ' . $e->getMessage()], 500);
    //             }
    //         }

    //         // Combine all the responses
    //         $combinedResult = $this->combineOllamaResponses($allResponses);

    //         // Return the combined result
    //         return $combinedResult;

    //     } catch (\Exception $e) {
    //         // Handle any exceptions (e.g., network issues)
    //         \Log::error('General error in analyzeResumeWithOllama: ' . $e->getMessage());
    //         return response()->json(['error' => 'Error in contacting Ollama API: ' . $e->getMessage()], 500);
    //     }
    // }


    // public function analyzeResumeWithOllama($resumeText)
    // {
    //     // Define the URL of your Flask app where Ollama is running
    //     $ollamaUrl = 'http://localhost:5001/llm'; // Flask server URL (where Ollama is running)

    //     try {
    //         // Define the structured JSON payload for Ollama
    //         $payload = [
    //             "prompt" => "You are an AI specialized in analyzing resumes for job applications. Perform the following tasks:
    //                 1. Extract key fields like:
    //                    - Name, Email, Phone, Skills, Experience, Education, Certifications, Projects, Summary, LinkedIn Profile, GitHub Profile.
    //                 2. Provide job analysis:
    //                    - Identify strengths, recommend job roles, ATS score, and improvement suggestions.",
    //             "resume_text" => $resumeText
    //         ];

    //         // Convert the array to a single-line JSON string
    //         $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    //         // Send the request to Ollama
    //         $response = Http::timeout(180) // Increase timeout to 2 minutes
    //             ->post($ollamaUrl, [
    //                 'text' => $jsonPayload // Sending the entire structured resume in a single field
    //             ]);
    //         dd($response);

    //         // Check if the request was successful
    //         if ($response->successful()) {
    //             return $response->json(); // Return the JSON response
    //         } else {
    //             // Log the error
    //             \Log::error('Ollama API Error: ' . $response->status() . ' - ' . $response->body());
    //             return response()->json(['error' => 'Failed to get analysis from Ollama'], 500);
    //         }

    //     } catch (\Exception $e) {
    //         // Handle any exceptions (e.g., network issues)
    //         \Log::error('Error in analyzeResumeWithOllama: ' . $e->getMessage());
    //         return response()->json(['error' => 'Error contacting Ollama API: ' . $e->getMessage()], 500);
    //     }
    // }

    // private function combineOllamaResponses($responses)
    // {
    //     $combined = [
    //         'name' => null,
    //         'email' => null,
    //         'phone' => null,
    //         'skills' => [],
    //         'experience' => [],
    //         'education' => [],
    //         'certifications' => [],
    //         'projects' => [],
    //         'summary' => null,
    //         'linkedin' => null,
    //         'github' => null,
    //         'job_analysis' => []
    //     ];

    //     foreach ($responses as $response) {
    //         // Adapt this to your actual response structure.

    //         // Combine Name
    //         if (isset($response['name']) && !$combined['name']) {
    //             $combined['name'] = $response['name'];
    //         }

    //         // Combine Email
    //         if (isset($response['email']) && !$combined['email']) {
    //             $combined['email'] = $response['email'];
    //         }

    //          // Combine Phone
    //         if (isset($response['phone']) && !$combined['phone']) {
    //             $combined['phone'] = $response['phone'];
    //         }

    //         // Combine Skills
    //         if (isset($response['skills'])) {
    //             $combined['skills'] = array_unique(array_merge($combined['skills'], $response['skills']));
    //         }

    //         // Combine Experience
    //         if (isset($response['experience'])) {
    //             $combined['experience'] = array_unique(array_merge($combined['experience'], $response['experience']));
    //         }

    //         // Combine Education
    //         if (isset($response['education'])) {
    //             $combined['education'] = array_unique(array_merge($combined['education'], $response['education']));
    //         }

    //         // Combine Certifications
    //         if (isset($response['certifications'])) {
    //             $combined['certifications'] = array_unique(array_merge($combined['certifications'], $response['certifications']));
    //         }

    //         // Combine Projects
    //         if (isset($response['projects'])) {
    //             $combined['projects'] = array_unique(array_merge($combined['projects'], $response['projects']));
    //         }

    //         // Combine Summary
    //         if (isset($response['summary']) && !$combined['summary']) {
    //             $combined['summary'] = $response['summary'];
    //         }

    //         // Combine LinkedIn
    //         if (isset($response['linkedin']) && !$combined['linkedin']) {
    //             $combined['linkedin'] = $response['linkedin'];
    //         }

    //         // Combine Github
    //         if (isset($response['github']) && !$combined['github']) {
    //             $combined['github'] = $response['github'];
    //         }

    //         // Combine Job Analysis
    //          if (isset($response['job_analysis'])) {
    //             $combined['job_analysis'] = array_unique(array_merge($combined['job_analysis'], $response['job_analysis']));
    //         }

    //     }

    //     return $combined;
    // }

    /**
     * Display the specified resource.
     */
    public function show(Resume $resume)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Resume $resume)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateResumeRequest $request, Resume $resume)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Resume $resume)
    {
        //
    }
}
