<?php

namespace Database\Seeders;

use App\Models\JobListing;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class JobListingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates a couple of generic sample job listings for local dev
     * convenience. Replaces the old JobsSeeder stub, which referenced the
     * retired `Jobs` model/table (removed in a prior task) and was never
     * wired into DatabaseSeeder.
     */
    public function run(): void
    {
        $listings = [
            [
                'api_id' => 'sample-001',
                'title' => 'Software Engineer',
                'company' => 'Example Corp',
                'location' => 'Remote',
                'description' => 'Example Corp is looking for a Software Engineer to help build and maintain our web applications.',
                'api_source' => 'seed',
                'posted_at' => now(),
                'is_active' => true,
                'parsed_skills' => ['PHP', 'Laravel', 'JavaScript'],
                'application_url' => 'https://example.com/careers/software-engineer',
            ],
            [
                'api_id' => 'sample-002',
                'title' => 'Backend Developer',
                'company' => 'Sample Industries',
                'location' => 'New York, NY',
                'description' => 'Sample Industries is seeking a Backend Developer to work on API design and database architecture.',
                'api_source' => 'seed',
                'posted_at' => now(),
                'is_active' => true,
                'parsed_skills' => ['PHP', 'MySQL', 'REST APIs'],
                'application_url' => 'https://example.com/careers/backend-developer',
            ],
        ];

        foreach ($listings as $listing) {
            JobListing::updateOrCreate(
                ['api_id' => $listing['api_id']],
                $listing
            );
        }
    }
}
