@extends('layouts/layoutMaster')

@section('title', 'Available Jobs')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/apex-charts/apex-charts.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/swiper/swiper.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/datatables-bs5/datatables.bootstrap5.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/select2/select2.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/datatables-responsive-bs5/responsive.bootstrap5.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css') }}" />
    <link rel="stylesheet"
        href="{{ asset('assets/vendor/libs/datatables-checkboxes-jquery/datatables.checkboxes.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/animate-css/animate.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/sweetalert2/sweetalert2.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/flatpickr/flatpickr.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/datatables-rowgroup-bs5/rowgroup.bootstrap5.css') }}" />
    <link rel="stylesheet"
        href="{{ asset('assets/vendor/libs/datatables-checkboxes-jquery/datatables.checkboxes.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/datatables-bs5/datatables.bootstrap5.css') }}" />
@endsection

@section('page-style')
    <!-- Page -->
    <link rel="stylesheet" href="{{ asset('assets/vendor/css/pages/cards-advance.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendor/css/pages/page-profile.css') }}">
    <style>
        .icon-lg {
            font-size: 2.8rem;
        }
    </style>
@endsection

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/apex-charts/apexcharts.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/select2/select2.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/swiper/swiper.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/datatables-bs5/datatables-bootstrap5.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/sweetalert2/sweetalert2.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/moment/moment.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/flatpickr/flatpickr.js') }}"></script>

@endsection

@section('page-script')
    <script src="{{ asset('assets/js/dashboards-analytics.js') }}"></script>
    <script src="{{ asset('assets/js/forms-selects.js') }}"></script>
    <script src="{{ asset('assets/js/extended-ui-perfect-scrollbar.js') }}"></script>
    <script src="{{ asset('assets/js/tables-datatables-basic.js') }}"></script>
@endsection

@section('content')
    <div class="content-wrapper">
        <!-- Content -->
        <div class="container-xxl flex-grow-1 container-p-y">
            <h4 class="py-3 mb-4"><span class="text-muted fw-light">Jobs/</span> View</h4>

            <div class="app-academy">
                <div class="card mb-4">
                    <div class="card-header d-flex flex-wrap justify-content-between gap-3">
                        <div class="card-title mb-0 me-1">
                            <h5 class="mb-1">Available Jobs</h5>
                            <p class="text-muted mb-0">
                                {{-- Optionally show total job count --}}
                                {{ array_sum(array_map(fn($jobs) => count($jobs), $resultsAdzuna)) }} jobs found
                        </div>
                        <div class="d-flex justify-content-md-end align-items-center gap-3 flex-wrap">
                            <!-- Optional filter controls -->
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row gy-4 mb-4">
                            <!-- Static Reference Card -->
                            <div class="col-sm-6 col-lg-4">
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <h5>Static Reference Job</h5>
                                    </div>
                                    <div class="card-body">
                                        <p><strong>Job Title:</strong> Senior Software Engineer</p>
                                        <p><strong>Company:</strong> TechCorp Inc.</p>
                                        <p><strong>Location:</strong> New York, NY</p>
                                        <p><strong>Date Posted:</strong> March 10, 2025</p>
                                        <p>
                                            <strong>Description:</strong>
                                            Lorem ipsum dolor sit amet, consectetur adipiscing elit. Vestibulum eget
                                            tincidunt nisi.
                                        </p>
                                        <p><strong>Portal:</strong> LinkedIn</p>
                                        <div class="d-flex justify-content-between">
                                            <button class="btn btn-outline-secondary btn-sm">Save Job</button>
                                            <a href="#" class="btn btn-primary btn-sm">Apply Now</a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row gy-4 mb-4">
                              @if($jobsResult->isNotEmpty())
                                @foreach($jobsResult as $job)
                                  <div class="col-sm-6 col-lg-4">
                                    <div class="card mb-4">
                                      <div class="card-header">
                                        <h5>{{ $job->title ?? 'Job Title N/A' }}</h5>
                                      </div>
                                      <div class="card-body">
                                        <p>
                                          <strong>Company:</strong> {{ $job->company ?? 'N/A' }}
                                        </p>
                                        <p>
                                          <strong>Location:</strong> {{ $job->location ?? 'N/A' }}
                                        </p>
                                        <p>
                                          <strong>Date Posted:</strong> {{ \Carbon\Carbon::parse($job->posted_at)->format('M d, Y') }}
                                        </p>
                                        <p>
                                          <strong>Description:</strong>
                                          {{ \Illuminate\Support\Str::limit($job->description ?? 'No description provided', 150) }}
                                        </p>
                                      </div>
                                      <div class="card-footer d-flex justify-content-between">
                                        <button class="btn btn-outline-secondary btn-sm">Save Job</button>
                                        {{-- <a href="{{ $job->application_url ?? '#' }}" target="_blank" class="btn btn-primary btn-sm">Apply Now</a> --}}
                                        <a href="{{ route('Jobs.show', ['Job' => $job->api_id]) }}" class="btn btn-primary btn-sm">Apply Now</a>

                                      </div>
                                    </div>
                                  </div>
                                @endforeach
                              @else
                                <div class="col-12">
                                  <p>No jobs found.</p>
                                </div>
                              @endif
                            </div>
                            
                        </div>
                        <!-- Pagination (optional) -->
                        <nav aria-label="Page navigation" class="d-flex align-items-center justify-content-center">
                            <ul class="pagination">
                                <li class="page-item prev">
                                    <a class="page-link" href="javascript:void(0);">
                                        <i class="ti ti-chevron-left ti-xs scaleX-n1-rtl"></i>
                                    </a>
                                </li>
                                <li class="page-item active">
                                    <a class="page-link" href="javascript:void(0);">1</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="javascript:void(0);">2</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="javascript:void(0);">3</a>
                                </li>
                                <li class="page-item next">
                                    <a class="page-link" href="javascript:void(0);">
                                        <i class="ti ti-chevron-right ti-xs scaleX-n1-rtl"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
        <!-- / Content -->
        <div class="content-backdrop fade"></div>
    </div>
@endsection


