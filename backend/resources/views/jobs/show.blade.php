@extends('layouts/layoutMaster')

@section('title', 'Job Details')

@section('vendor-style')
    <!-- Include additional vendor CSS if required -->
@endsection

@section('vendor-script')
    <!-- Include vendor scripts if needed -->
@endsection

@section('page-script')
    <!-- Include page-specific scripts if needed -->
@endsection


@section('page-style')
    <style>
        .job-header {
            margin-bottom: 1rem;
        }
        .job-details {
            margin-bottom: 1rem;
        }
        .job-description {
            white-space: pre-wrap;
            line-height: 1.5;
        }
        .button-group a {
            margin-right: 1rem;
        }
    </style>
@endsection

@section('content')
<div class="content-wrapper">
    <div class="container-xxl flex-grow-1 container-p-y">
        <h4 class="py-3 mb-4">
            <span class="text-muted fw-light">Job /</span> Details
        </h4>

        <div class="card">
            <div class="card-header job-header">
                <h2>{{ $job->title }}</h2>
                <p class="text-muted">{{ $job->company }}</p>
            </div>
            <div class="card-body job-details">
                <p><strong>Location:</strong> {{ $job->location }}</p>
                <p><strong>Date Posted:</strong> {{ \Carbon\Carbon::parse($job->posted_at)->format('M d, Y') }}</p>
                <hr>
                <div class="job-description">
                    {!! nl2br(e($job->description)) !!}
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between button-group">
                <a href="{{ $job->application_url }}" class="btn btn-primary">Apply Now</a>
                {{-- <a href="{{ route('Jobs.save', ['Job' => $job->api_id]) }}" class="btn btn-outline-secondary">Save Job</a> --}}
                <a href="" class="btn btn-success">Download Tailored Resume</a>
            </div>
        </div>
    </div>
</div>
@endsection

