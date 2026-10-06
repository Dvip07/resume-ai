<?php


namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use SebastianBergmann\CodeCoverage\Report\Html\Dashboard;

class DashboardController extends Controller
{
    public function index(){
        return view('dashboard.dashboards-analytics');
    }

    public function crm(){
        return view('dashboard.dashboards-crm');
    }

    public function view(){
        return view('dashboard.view-profile');
    }

    public function add(){
        return view('dashboard.add-post');
    }
}
