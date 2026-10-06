<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreuserProfileRequest;
use App\Http\Requests\UpdateuserProfileRequest;
use App\Models\userProfile;


class UserProfileController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
    public function store(StoreuserProfileRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(userProfile $userProfile)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(userProfile $userProfile)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateuserProfileRequest $request, userProfile $userProfile)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(userProfile $userProfile)
    {
        //
    }
}
