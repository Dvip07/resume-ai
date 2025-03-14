<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ResumeUpload;
use Spatie\PdfToText\Pdf;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Arr;
use App\Jobs\AnalyzeResumeJob;


class ResumeUploadController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('ResumeUpload.index');
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
        $resumeFile = $request->file('resume');
        $originalFileName = $resumeFile->getClientOriginalName();
        $filePath = $resumeFile->storeAs('resume', $originalFileName, 'public');

        try {
            $pdf = new Pdf();
            $resumeText = $pdf->setPdf(storage_path('app/public/' . $filePath))->text();
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error extracting text from resume.');
        }

        // Save initial resume record
        $resumeUpload = ResumeUpload::create([
            'user_id' => auth()->id(),
            'original_resume_path' => $originalFileName,
            'parsed_resume_path' => $filePath,
            'parsed_data' => $resumeText,
        ]);

        // Dispatch the analysis job to the queue
        // AnalyzeResumeJob::dispatch($resume, $resumeText);
        // dd($resumeText);
        AnalyzeResumeJob::dispatch($resumeUpload, $resumeText);


        // dd($resumeText);


        return redirect()->route('resumes.index')->with('success', 'Resume uploaded. Analysis is in progress.');
    }
    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
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
