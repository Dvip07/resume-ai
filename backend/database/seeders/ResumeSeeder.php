<?php

namespace Database\Seeders;

use App\Models\Resume;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ResumeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates a single generic sample resume for the seeded "Test User"
     * (created by DatabaseSeeder) for local dev convenience. No real
     * personal data — placeholder values only.
     */
    public function run(): void
    {
        $user = User::firstWhere('email', 'test@example.com');

        if (! $user) {
            return;
        }

        Resume::firstOrCreate(
            [
                'user_id' => $user->id,
                'original_filename' => 'sample-resume.pdf',
            ],
            [
                'file_path' => 'resumes/sample-resume.pdf',
                'parsed_data' => [
                    'skills' => [
                        'primary' => ['PHP', 'Laravel', 'JavaScript'],
                        'secondary' => ['React', 'MySQL', 'Git'],
                    ],
                    'workExperience' => [
                        [
                            'companyName' => 'Example Corp',
                            'position' => 'Software Developer',
                            'duration' => 12,
                            'achievements' => [
                                'Built and maintained internal web applications',
                            ],
                        ],
                    ],
                    'education' => [
                        'institution' => ['Example State University'],
                        'degree' => [],
                        'fieldOfStudy' => [],
                        'yearsAttended' => [['startYear' => 2018, 'endYear' => 2022]],
                    ],
                    'summary' => 'Software developer with experience building web applications.',
                ],
                'job_analysis' => null,
                'ats_score' => null,
                'is_optimized' => false,
            ]
        );
    }
}
