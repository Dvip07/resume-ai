@extends('layouts/layoutMaster')

@section('title', 'Analytics')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/apex-charts/apex-charts.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/select2/select2.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/sweetalert2/sweetalert2.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/flatpickr/flatpickr.css') }}" />
@endsection

@section('page-style')
    <style>
        .icon-lg {
            font-size: 2.8rem;
        }
    </style>
@endsection

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/apex-charts/apexcharts.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/select2/select2.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/sweetalert2/sweetalert2.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/flatpickr/flatpickr.js') }}"></script>
@endsection



@section('content')

    <div class="content-wrapper">
        <div class="card">
            <h5 class="card-header">Upload Your Resume</h5>
            <div class="card-body">
                <form id="resumeUploadForm" method="POST" action="{{ route('ResumeUpload.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label for="formFile" class="form-label">Resume (Only PDF file supported)</label>
                        <input class="form-control" type="file" id="formFile" accept=".pdf" name="resume" />
                    </div>
                    <div class="col-12 text-start">
                        <button type="submit" class="btn rounded-pill btn-success">Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection

@section('page-script')
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            document.getElementById("resumeUploadForm").addEventListener("submit", function (event) {
                let fileInput = document.getElementById("formFile");
                let file = fileInput.files[0];

                if (!file) {
                    Swal.fire("Error!", "Please select a file to upload.", "error");
                    event.preventDefault();
                    return;
                }

                let allowedExtensions = ["pdf"];
                let fileExtension = file.name.split(".").pop().toLowerCase();

                if (!allowedExtensions.includes(fileExtension)) {
                    Swal.fire("Invalid File!", "Only PDF Files are allowed.", "warning");
                    fileInput.value = "";
                    event.preventDefault();
                }
            });
        });
    </script>
@endsection
