<?php

namespace App\Http\Controllers\authenticate;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AuthLogin extends Controller
{
    //
    public function login()
    {
        $pageConfigs = ['myLayout' => 'blank'];
        return view('authenticate.authenticate-login', ['pageConfigs' => $pageConfigs]);
    }

    public function register()
    {
        $pageConfigs = ['myLayout' => 'blank'];
        return view('authenticate.authenticate-register', ['pageConfigs' => $pageConfigs]);
    }
}
