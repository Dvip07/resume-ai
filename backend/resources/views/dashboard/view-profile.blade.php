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

        <!-- Header -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="user-profile-header-banner">
                        <img src="../../assets/img/pages/profile-banner.png" alt="Banner image" class="rounded-top" />
                    </div>
                    <div class="user-profile-header d-flex flex-column flex-sm-row text-sm-start text-center mb-4">
                        <div class="flex-shrink-0 mt-n2 mx-sm-0 mx-auto">
                            <img src="../../assets/img/avatars/14.png" alt="user image"
                                class="d-block h-auto ms-0 ms-sm-4 rounded user-profile-img" />
                        </div>
                        <div class="flex-grow-1 mt-3 mt-sm-5">
                            <div
                                class="d-flex align-items-md-end align-items-sm-start align-items-center justify-content-md-between justify-content-start mx-4 flex-md-row flex-column gap-4">
                                <div class="user-profile-info">
                                    <h4>John Doe</h4>
                                    <ul
                                        class="list-inline mb-0 d-flex align-items-center flex-wrap justify-content-sm-start justify-content-center gap-2">
                                        <li class="list-inline-item d-flex gap-1">
                                            <i class="ti ti-color-swatch"></i> UX Designer
                                        </li>
                                        <li class="list-inline-item d-flex gap-1"><i class="ti ti-map-pin"></i> Vatican City
                                        </li>
                                        <li class="list-inline-item d-flex gap-1">
                                            <i class="ti ti-calendar"></i> Joined April 2021
                                        </li>
                                    </ul>
                                </div>
                                <a href="javascript:void(0)" class="btn btn-primary">
                                    <i class="ti ti-check me-1"></i>Connected
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!--/ Header -->


        <!-- Navbar pills -->
        {{-- <div class="row">
            <div class="col-md-12">
                <ul class="nav nav-pills flex-column flex-sm-row mb-4">
                    <li class="nav-item">
                        <a class="nav-link active" href="javascript:void(0);"><i class="ti-xs ti ti-user-check me-1"></i>
                            Profile</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pages-profile-teams.html"><i class="ti-xs ti ti-users me-1"></i> Teams</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pages-profile-projects.html"><i class="ti-xs ti ti-layout-grid me-1"></i>
                            Projects</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pages-profile-connections.html"><i class="ti-xs ti ti-link me-1"></i>
                            Connections</a>
                    </li>
                </ul>
            </div>
        </div> --}}
        <!--/ Navbar pills -->

        <div class="row">
            <div class="col-xl-4 col-lg-5 col-md-5">
                <!-- About User -->
                {{-- <div class="card mb-4">
                    <div class="card-body">
                        <small class="card-text text-uppercase">About</small>
                        <ul class="list-unstyled mb-4 mt-3">
                            <li class="d-flex align-items-center mb-3">
                                <i class="ti ti-user text-heading"></i><span class="fw-medium mx-2 text-heading">Full
                                    Name:</span> <span>John Doe</span>
                            </li>
                            <li class="d-flex align-items-center mb-3">
                                <i class="ti ti-check text-heading"></i><span
                                    class="fw-medium mx-2 text-heading">Status:</span> <span>Active</span>
                            </li>
                            <li class="d-flex align-items-center mb-3">
                                <i class="ti ti-crown text-heading"></i><span
                                    class="fw-medium mx-2 text-heading">Role:</span> <span>Developer</span>
                            </li>
                            <li class="d-flex align-items-center mb-3">
                                <i class="ti ti-flag text-heading"></i><span
                                    class="fw-medium mx-2 text-heading">Country:</span> <span>USA</span>
                            </li>
                            <li class="d-flex align-items-center mb-3">
                                <i class="ti ti-file-description text-heading"></i><span
                                    class="fw-medium mx-2 text-heading">Languages:</span> <span>English</span>
                            </li>
                        </ul>
                        <small class="card-text text-uppercase">Contacts</small>
                        <ul class="list-unstyled mb-4 mt-3">
                            <li class="d-flex align-items-center mb-3">
                                <i class="ti ti-phone-call"></i><span class="fw-medium mx-2 text-heading">Contact:</span>
                                <span>(123) 456-7890</span>
                            </li>
                            <li class="d-flex align-items-center mb-3">
                                <i class="ti ti-brand-skype"></i><span class="fw-medium mx-2 text-heading">Skype:</span>
                                <span>john.doe</span>
                            </li>
                            <li class="d-flex align-items-center mb-3">
                                <i class="ti ti-mail"></i><span class="fw-medium mx-2 text-heading">Email:</span>
                                <span>john.doe@example.com</span>
                            </li>
                        </ul>
                        <small class="card-text text-uppercase">Teams</small>
                        <ul class="list-unstyled mb-0 mt-3">
                            <li class="d-flex align-items-center mb-3">
                                <i class="ti ti-brand-angular text-danger me-2"></i>
                                <div class="d-flex flex-wrap">
                                    <span class="fw-medium me-2 text-heading">Backend Developer</span><span>(126
                                        Members)</span>
                                </div>
                            </li>
                            <li class="d-flex align-items-center">
                                <i class="ti ti-brand-react-native text-info me-2"></i>
                                <div class="d-flex flex-wrap">
                                    <span class="fw-medium me-2 text-heading">React Developer</span><span>(98
                                        Members)</span>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div> --}}
                <!--/ About User -->
                <!-- User Card -->
                <div class="card mb-4">
                    <div class="card-body">
                      <div class="user-avatar-section">
                        <div class="d-flex align-items-center flex-column">
                          <img
                            class="img-fluid rounded mb-3 pt-1 mt-4"
                            src="../../assets/img/avatars/15.png"
                            height="100"
                            width="100"
                            alt="User avatar" />
                          <div class="user-info text-center">
                            <h4 class="mb-2">Violet Mendoza</h4>
                            <span class="badge bg-label-secondary mt-1">Author</span>
                          </div>
                        </div>
                      </div>
                      <div class="d-flex justify-content-around flex-wrap mt-3 pt-3 pb-4 border-bottom">
                        <div class="d-flex align-items-start me-4 mt-3 gap-2">
                          <span class="badge bg-label-primary p-2 rounded"><i class="ti ti-checkbox ti-sm"></i></span>
                          <div>
                            <p class="mb-0 fw-medium">1.23k</p>
                            <small>Tasks Done</small>
                          </div>
                        </div>
                        <div class="d-flex align-items-start mt-3 gap-2">
                          <span class="badge bg-label-primary p-2 rounded"><i class="ti ti-briefcase ti-sm"></i></span>
                          <div>
                            <p class="mb-0 fw-medium">568</p>
                            <small>Projects Done</small>
                          </div>
                        </div>
                      </div>
                      <h5 class="mt-4 small text-uppercase text-muted">Details</h5>
                      <div class="info-container">
                        <ul class="list-unstyled">
                          <li class="mb-2">
                            <span class="fw-medium me-1">Username:</span>
                            <span>violet.dev</span>
                          </li>
                          <li class="mb-2 pt-1">
                            <span class="fw-medium me-1">Email:</span>
                            <span>vafgot@vultukir.org</span>
                          </li>
                          <li class="mb-2 pt-1">
                            <span class="fw-medium me-1">Status:</span>
                            <span class="badge bg-label-success">Active</span>
                          </li>
                          <li class="mb-2 pt-1">
                            <span class="fw-medium me-1">Role:</span>
                            <span>Author</span>
                          </li>
                          <li class="mb-2 pt-1">
                            <span class="fw-medium me-1">Tax id:</span>
                            <span>Tax-8965</span>
                          </li>
                          <li class="mb-2 pt-1">
                            <span class="fw-medium me-1">Contact:</span>
                            <span>(123) 456-7890</span>
                          </li>
                          <li class="mb-2 pt-1">
                            <span class="fw-medium me-1">Languages:</span>
                            <span>French</span>
                          </li>
                          <li class="pt-1">
                            <span class="fw-medium me-1">Country:</span>
                            <span>England</span>
                          </li>
                        </ul>
                        <div class="d-flex justify-content-center">
                          <a
                            href="javascript:;"
                            class="btn btn-primary me-3"
                            data-bs-target="#editUser"
                            data-bs-toggle="modal"
                            >Edit</a
                          >
                          <a href="javascript:;" class="btn btn-label-danger suspend-user">Suspended</a>
                        </div>
                      </div>
                    </div>
                  </div>
                  <!-- /User Card -->
            </div>

            <div class="col-xl-8 col-lg-7 col-md-7">

                <div class="card mb-4">
                    <h5 class="card-header">Change Password</h5>
                    <div class="card-body">
                        <form id="formChangePassword" method="POST" onsubmit="return false">
                            <div class="alert alert-warning" role="alert">
                                <h5 class="alert-heading mb-2">Ensure that these requirements are met</h5>
                                <span>Minimum 8 characters long, uppercase & symbol</span>
                            </div>
                            <div class="row">
                                <div class="mb-3 col-12 col-sm-6 form-password-toggle">
                                    <label class="form-label" for="newPassword">New Password</label>
                                    <div class="input-group input-group-merge">
                                        <input class="form-control" type="password" id="newPassword" name="newPassword"
                                            placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;" />
                                        <span class="input-group-text cursor-pointer"><i class="ti ti-eye-off"></i></span>
                                    </div>
                                </div>

                                <div class="mb-3 col-12 col-sm-6 form-password-toggle">
                                    <label class="form-label" for="confirmPassword">Confirm New Password</label>
                                    <div class="input-group input-group-merge">
                                        <input class="form-control" type="password" name="confirmPassword"
                                            id="confirmPassword"
                                            placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;" />
                                        <span class="input-group-text cursor-pointer"><i class="ti ti-eye-off"></i></span>
                                    </div>
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary me-2">Change Password</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                  <!-- Connected Accounts -->
                  <div class="card mb-4">
                    <h5 class="card-header pb-1">Connected Accounts</h5>
                    <div class="card-body">
                      <p class="mb-4">Display content from your connected accounts on your site</p>
                      <div class="d-flex mb-3">
                        <div class="flex-shrink-0">
                          <img src="../../assets/img/icons/brands/google.png" alt="google" class="me-3" height="38" />
                        </div>
                        <div class="flex-grow-1 row">
                          <div class="col-9 mb-sm-0 mb-2">
                            <h6 class="mb-0">Google</h6>
                            <small class="text-muted">Calendar and contacts</small>
                          </div>
                          <div class="col-3 d-flex align-items-center justify-content-end">
                            <div class="form-check form-switch">
                              <input class="form-check-input float-end" type="checkbox" checked />
                            </div>
                          </div>
                        </div>
                      </div>
                      <div class="d-flex mb-3">
                        <div class="flex-shrink-0">
                          <img src="../../assets/img/icons/brands/slack.png" alt="slack" class="me-3" height="38" />
                        </div>
                        <div class="flex-grow-1 row">
                          <div class="col-9 mb-sm-0 mb-2">
                            <h6 class="mb-0">Slack</h6>
                            <small class="text-muted">Communication</small>
                          </div>
                          <div class="col-3 d-flex align-items-center justify-content-end">
                            <div class="form-check form-switch">
                              <input class="form-check-input float-end" type="checkbox" />
                            </div>
                          </div>
                        </div>
                      </div>
                      <div class="d-flex mb-3">
                        <div class="flex-shrink-0">
                          <img src="../../assets/img/icons/brands/github.png" alt="github" class="me-3" height="38" />
                        </div>
                        <div class="flex-grow-1 row">
                          <div class="col-9 mb-sm-0 mb-2">
                            <h6 class="mb-0">Github</h6>
                            <small class="text-muted">Manage your Git repositories</small>
                          </div>
                          <div class="col-3 d-flex align-items-center justify-content-end">
                            <div class="form-check form-switch">
                              <input class="form-check-input float-end" type="checkbox" checked />
                            </div>
                          </div>
                        </div>
                      </div>
                      <div class="d-flex mb-3">
                        <div class="flex-shrink-0">
                          <img
                            src="../../assets/img/icons/brands/mailchimp.png"
                            alt="mailchimp"
                            class="me-3"
                            height="38" />
                        </div>
                        <div class="flex-grow-1 row">
                          <div class="col-9 mb-sm-0 mb-2">
                            <h6 class="mb-0">Mailchimp</h6>
                            <small class="text-muted">Email marketing service</small>
                          </div>
                          <div class="col-3 d-flex align-items-center justify-content-end">
                            <div class="form-check form-switch">
                              <input class="form-check-input float-end" type="checkbox" checked />
                            </div>
                          </div>
                        </div>
                      </div>
                      <div class="d-flex">
                        <div class="flex-shrink-0">
                          <img src="../../assets/img/icons/brands/asana.png" alt="asana" class="me-3" height="38" />
                        </div>
                        <div class="flex-grow-1 row">
                          <div class="col-9 mb-sm-0 mb-2">
                            <h6 class="mb-0">Asana</h6>
                            <small class="text-muted">Communication</small>
                          </div>
                          <div class="col-3 d-flex align-items-center justify-content-end">
                            <div class="form-check form-switch">
                              <input class="form-check-input float-end" type="checkbox" />
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                  <!-- /Connected Accounts -->

                  <!-- Social Accounts -->
                  <div class="card mb-4">
                    <h5 class="card-header pb-1">Social Accounts</h5>
                    <div class="card-body">
                      <p class="mb-4">Display content from social accounts on your site</p>
                      <div class="d-flex mb-3">
                        <div class="flex-shrink-0">
                          <img
                            src="../../assets/img/icons/brands/facebook.png"
                            alt="facebook"
                            class="me-3"
                            height="38" />
                        </div>
                        <div class="flex-grow-1 row">
                          <div class="col-7 mb-sm-0 mb-2">
                            <h6 class="mb-0">Facebook</h6>
                            <small class="text-muted">Not Connected</small>
                          </div>
                          <div class="col-5 text-end">
                            <button class="btn btn-label-secondary btn-icon"><i class="ti ti-link ti-sm"></i></button>
                          </div>
                        </div>
                      </div>
                      <div class="d-flex mb-3">
                        <div class="flex-shrink-0">
                          <img src="../../assets/img/icons/brands/twitter.png" alt="twitter" class="me-3" height="38" />
                        </div>
                        <div class="flex-grow-1 row">
                          <div class="col-7 mb-sm-0 mb-2">
                            <h6 class="mb-0">Twitter</h6>
                            <a href="https://twitter.com/pixinvents" target="_blank">@Pixinvent</a>
                          </div>
                          <div class="col-5 text-end">
                            <button class="btn btn-label-danger btn-icon"><i class="ti ti-trash ti-sm"></i></button>
                          </div>
                        </div>
                      </div>
                      <div class="d-flex mb-3">
                        <div class="flex-shrink-0">
                          <img
                            src="../../assets/img/icons/brands/instagram.png"
                            alt="instagram"
                            class="me-3"
                            height="38" />
                        </div>
                        <div class="flex-grow-1 row">
                          <div class="col-7 mb-sm-0 mb-2">
                            <h6 class="mb-0">instagram</h6>
                            <a href="https://www.instagram.com/pixinvents/" target="_blank">@Pixinvent</a>
                          </div>
                          <div class="col-5 text-end">
                            <button class="btn btn-label-danger btn-icon"><i class="ti ti-trash ti-sm"></i></button>
                          </div>
                        </div>
                      </div>
                      <div class="d-flex mb-3">
                        <div class="flex-shrink-0">
                          <img
                            src="../../assets/img/icons/brands/dribbble.png"
                            alt="dribbble"
                            class="me-3"
                            height="38" />
                        </div>
                        <div class="flex-grow-1 row">
                          <div class="col-7 mb-sm-0 mb-2">
                            <h6 class="mb-0">Dribbble</h6>
                            <small class="text-muted">Not Connected</small>
                          </div>
                          <div class="col-5 text-end">
                            <button class="btn btn-label-secondary btn-icon"><i class="ti ti-link ti-sm"></i></button>
                          </div>
                        </div>
                      </div>
                      <div class="d-flex">
                        <div class="flex-shrink-0">
                          <img src="../../assets/img/icons/brands/behance.png" alt="behance" class="me-3" height="38" />
                        </div>
                        <div class="flex-grow-1 row">
                          <div class="col-7 mb-sm-0 mb-2">
                            <h6 class="mb-0">Behance</h6>
                            <small class="text-muted">Not Connected</small>
                          </div>
                          <div class="col-5 text-end">
                            <button class="btn btn-label-secondary btn-icon"><i class="ti ti-link ti-sm"></i></button>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <!-- /Social Accounts -->
            </div>
        </div>
    </div>

@endsection
