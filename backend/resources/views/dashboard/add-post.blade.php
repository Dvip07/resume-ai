@extends('layouts/layoutMaster')

@section('title', 'Analytics')

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
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/bs-stepper/bs-stepper.css') }}" />
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
    <script src="{{ asset('assets/vendor/libs/bs-stepper/bs-stepper.js') }}"></script>
@endsection

@section('page-script')
    <script src="{{ asset('assets/js/dashboards-analytics.js') }}"></script>
    <script src="{{ asset('assets/js/forms-selects.js') }}"></script>
    <script src="{{ asset('assets/js/extended-ui-perfect-scrollbar.js') }}"></script>
    <script src="{{ asset('assets/js/tables-datatables-basic.js') }}"></script>
    <script src="{{ asset('assets/js/form-wizard-icons.js') }}"></script>
@endsection

@section('content')

    <div class="content-wrapper">

        <!-- Modern Icons Wizard -->
        <div class="col-12 mb-4">
            <div class="bs-stepper wizard-icons wizard-modern wizard-modern-icons-example mt-2">
                <div class="bs-stepper-header">
                    <div class="step" data-target="#account-details-modern">
                        <button type="button" class="step-trigger">
                            <span class="bs-stepper-icon">
                                <svg viewBox="0 0 54 54">
                                    <use xlink:href="../../assets/svg/icons/form-wizard-account.svg#wizardAccount"></use>
                                </svg>
                            </span>
                            <span class="bs-stepper-label">Account Details</span>
                        </button>
                    </div>
                    <div class="line">
                        <i class="ti ti-chevron-right"></i>
                    </div>
                    <div class="step" data-target="#personal-info-modern">
                        <button type="button" class="step-trigger">
                            <span class="bs-stepper-icon">
                                <svg viewBox="0 0 58 54">
                                    <use xlink:href="../../assets/svg/icons/form-wizard-personal.svg#wizardPersonal"></use>
                                </svg>
                            </span>
                            <span class="bs-stepper-label">Personal Info</span>
                        </button>
                    </div>
                    <div class="line">
                        <i class="ti ti-chevron-right"></i>
                    </div>
                    <div class="step" data-target="#address-modern">
                        <button type="button" class="step-trigger">
                            <span class="bs-stepper-icon">
                                <svg viewBox="0 0 54 54">
                                    <use xlink:href="../../assets/svg/icons/form-wizard-address.svg#wizardAddress"></use>
                                </svg>
                            </span>
                            <span class="bs-stepper-label">Address</span>
                        </button>
                    </div>
                    <div class="line">
                        <i class="ti ti-chevron-right"></i>
                    </div>
                    <div class="step" data-target="#social-links-modern">
                        <button type="button" class="step-trigger">
                            <span class="bs-stepper-icon">
                                <svg viewBox="0 0 54 54">
                                    <use xlink:href="../../assets/svg/icons/form-wizard-social-link.svg#wizardSocialLink">
                                    </use>
                                </svg>
                            </span>
                            <span class="bs-stepper-label">Social Links</span>
                        </button>
                    </div>
                    <div class="line">
                        <i class="ti ti-chevron-right"></i>
                    </div>
                    <div class="step" data-target="#review-submit-modern">
                        <button type="button" class="step-trigger">
                            <span class="bs-stepper-icon">
                                <svg viewBox="0 0 54 54">
                                    <use xlink:href="../../assets/svg/icons/form-wizard-submit.svg#wizardSubmit"></use>
                                </svg>
                            </span>
                            <span class="bs-stepper-label">Review & Submit</span>
                        </button>
                    </div>
                </div>
                <div class="bs-stepper-content">
                    <form onSubmit="return false">
                        <!-- Account Details -->
                        <div id="account-details-modern" class="content">
                            <div class="content-header mb-3">
                                <h6 class="mb-0">Account Details</h6>
                                <small>Enter Your Account Details.</small>
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-12">
                                    <label class="form-label" for="username-modern">Post Title</label>
                                    <input type="text" id="username-modern" class="form-control" placeholder="johndoe" />
                                </div>
                                <div class="col-sm-12">
                                    <label class="form-label" for="email-modern">Post Description</label>
                                    <input type="email" id="email-modern" class="form-control"
                                        placeholder="john.doe@email.com" aria-label="john.doe" />
                                </div>
                                
                                <div class="col-12 d-flex justify-content-between">
                                    <button class="btn btn-label-secondary btn-prev" disabled>
                                        <i class="ti ti-arrow-left me-sm-1"></i>
                                        <span class="align-middle d-sm-inline-block d-none">Previous</span>
                                    </button>
                                    <button class="btn btn-primary btn-next">
                                        <span class="align-middle d-sm-inline-block d-none me-sm-1">Next</span>
                                        <i class="ti ti-arrow-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <!-- Personal Info -->
                        <div id="personal-info-modern" class="content">
                            <div class="content-header mb-3">
                                <h6 class="mb-0">Personal Info</h6>
                                <small>Enter Your Personal Info.</small>
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="form-label" for="first-name-modern">First Name</label>
                                    <input type="text" id="first-name-modern" class="form-control"
                                        placeholder="John" />
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="last-name-modern">Last Name</label>
                                    <input type="text" id="last-name-modern" class="form-control"
                                        placeholder="Doe" />
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="country-modern">Country</label>
                                    <select class="select2" id="country-modern">
                                        <option label=" "></option>
                                        <option>UK</option>
                                        <option>USA</option>
                                        <option>Spain</option>
                                        <option>France</option>
                                        <option>Italy</option>
                                        <option>Australia</option>
                                    </select>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="language-modern">Language</label>
                                    <select class="selectpicker w-auto" id="language-modern" data-style="btn-transparent"
                                        data-icon-base="ti" data-tick-icon="ti-check text-white" multiple>
                                        <option>English</option>
                                        <option>French</option>
                                        <option>Spanish</option>
                                    </select>
                                </div>
                                <div class="col-12 d-flex justify-content-between">
                                    <button class="btn btn-label-secondary btn-prev">
                                        <i class="ti ti-arrow-left me-sm-1"></i>
                                        <span class="align-middle d-sm-inline-block d-none">Previous</span>
                                    </button>
                                    <button class="btn btn-primary btn-next">
                                        <span class="align-middle d-sm-inline-block d-none me-sm-1">Next</span>
                                        <i class="ti ti-arrow-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <!-- Address -->
                        <div id="address-modern" class="content">
                            <div class="content-header mb-3">
                                <h6 class="mb-0">Address</h6>
                                <small>Enter Your Address.</small>
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="form-label" for="address-modern-input">Address</label>
                                    <input type="text" class="form-control" id="address-modern-input"
                                        placeholder="98  Borough bridge Road, Birmingham" />
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="landmark-modern">Landmark</label>
                                    <input type="text" class="form-control" id="landmark-modern"
                                        placeholder="Borough bridge" />
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="pincode-modern">Pincode</label>
                                    <input type="text" class="form-control" id="pincode-modern"
                                        placeholder="658921" />
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="city-modern">City</label>
                                    <input type="text" class="form-control" id="city-modern"
                                        placeholder="Birmingham" />
                                </div>
                                <div class="col-12 d-flex justify-content-between">
                                    <button class="btn btn-label-secondary btn-prev">
                                        <i class="ti ti-arrow-left me-sm-1"></i>
                                        <span class="align-middle d-sm-inline-block d-none">Previous</span>
                                    </button>
                                    <button class="btn btn-primary btn-next">
                                        <span class="align-middle d-sm-inline-block d-none me-sm-1">Next</span>
                                        <i class="ti ti-arrow-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <!-- Social Links -->
                        <div id="social-links-modern" class="content">
                            <div class="content-header mb-3">
                                <h6 class="mb-0">Social Links</h6>
                                <small>Enter Your Social Links.</small>
                            </div>

                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="form-label" for="twitter-modern">Twitter</label>
                                    <input type="text" id="twitter-modern" class="form-control"
                                        placeholder="https://twitter.com/abc" />
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="facebook-modern">Facebook</label>
                                    <input type="text" id="facebook-modern" class="form-control"
                                        placeholder="https://facebook.com/abc" />
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="google-modern">Google+</label>
                                    <input type="text" id="google-modern" class="form-control"
                                        placeholder="https://plus.google.com/abc" />
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="linkedin-modern">Linkedin</label>
                                    <input type="text" id="linkedin-modern" class="form-control"
                                        placeholder="https://linkedin.com/abc" />
                                </div>
                                <div class="col-12 d-flex justify-content-between">
                                    <button class="btn btn-label-secondary btn-prev">
                                        <i class="ti ti-arrow-left me-sm-1"></i>
                                        <span class="align-middle d-sm-inline-block d-none">Previous</span>
                                    </button>
                                    <button class="btn btn-primary btn-next">
                                        <span class="align-middle d-sm-inline-block d-none me-sm-1">Next</span>
                                        <i class="ti ti-arrow-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <!-- Review -->
                        <div id="review-submit-modern" class="content">
                            <p class="fw-medium mb-2">Account</p>
                            <ul class="list-unstyled">
                                <li>Username</li>
                                <li>exampl@email.com</li>
                            </ul>
                            <hr />
                            <p class="fw-medium mb-2">Personal Info</p>
                            <ul class="list-unstyled">
                                <li>First Name</li>
                                <li>Last Name</li>
                                <li>Country</li>
                                <li>Language</li>
                            </ul>
                            <hr />
                            <p class="fw-medium mb-2">Address</p>
                            <ul class="list-unstyled">
                                <li>Address</li>
                                <li>Landmark</li>
                                <li>Pincode</li>
                                <li>City</li>
                            </ul>
                            <hr />
                            <p class="fw-medium mb-2">Social Links</p>
                            <ul class="list-unstyled">
                                <li>https://twitter.com/abc</li>
                                <li>https://facebook.com/abc</li>
                                <li>https://plus.google.com/abc</li>
                                <li>https://linkedin.com/abc</li>
                            </ul>
                            <div class="col-12 d-flex justify-content-between">
                                <button class="btn btn-label-secondary btn-prev">
                                    <i class="ti ti-arrow-left me-sm-1"></i>
                                    <span class="align-middle d-sm-inline-block d-none">Previous</span>
                                </button>
                                <button class="btn btn-success btn-submit">Submit</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- /Modern Icons Wizard -->
    </div>

@endsection
