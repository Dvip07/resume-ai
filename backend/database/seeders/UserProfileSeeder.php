<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Attaches a generic, non-personal sample profile to the seeded
     * "Test User" (created by DatabaseSeeder) for local dev convenience.
     * No real personal data — placeholder values only.
     */
    public function run(): void
    {
        $user = User::firstWhere('email', 'test@example.com');

        if (! $user) {
            return;
        }

        UserProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'skills' => [
                    'primary' => ['PHP', 'Laravel', 'JavaScript'],
                    'secondary' => ['React', 'MySQL', 'Git'],
                ],
                'location' => [
                    'city' => 'Springfield',
                    'state' => 'IL',
                    'country' => 'USA',
                ],
                'linkedin_url' => 'https://linkedin.com/in/jane-doe',
                'github_url' => 'https://github.com/jane-doe',
                'portfolio_url' => 'https://jane-doe.example.com',
                'suggested_roles' => ['Software Engineer', 'Backend Developer'],
                'experience' => [
                    [
                        'company' => 'Example Corp',
                        'position' => 'Software Developer',
                        'duration' => 12,
                        'achievements' => [
                            'Built and maintained internal web applications',
                            'Collaborated with a small team on feature delivery',
                        ],
                    ],
                ],
                'education' => [
                    [
                        'institution' => 'Example State University',
                        'degree' => 'B.S.',
                        'fieldOfStudy' => 'Computer Science',
                        'startYear' => 2018,
                        'endYear' => 2022,
                    ],
                ],
                'parsed_keywords' => 'PHP, Laravel, JavaScript, React, MySQL',
                'resume_text' => "Jane Doe\njane.doe@example.com\nSpringfield, IL\n\nSoftware developer with experience building web applications.",
            ]
        );
    }
}
