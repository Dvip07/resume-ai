<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the manual profile edit API
 * (App\Http\Controllers\Api\ProfileController / routes/api.php).
 *
 * Validates: Requirements 2.5
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_without_authorization_header_returns_401(): void
    {
        $response = $this->getJson('/api/profile');

        $response->assertStatus(401);
    }

    public function test_update_without_authorization_header_returns_401(): void
    {
        $response = $this->patchJson('/api/profile', [
            'skills' => ['PHP'],
        ]);

        $response->assertStatus(401);
    }

    public function test_show_returns_404_when_no_profile_exists_yet(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/profile');

        $response->assertStatus(404);
    }

    public function test_show_returns_the_authenticated_users_profile(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $profile = UserProfile::create([
            'user_id' => $user->id,
            'skills' => ['PHP', 'Laravel'],
            'location' => ['city' => 'Toronto', 'country' => 'Canada'],
            'linkedin_url' => 'https://linkedin.com/in/example',
            'github_url' => '',
            'portfolio_url' => '',
            'suggested_roles' => [],
            'experience' => [],
            'education' => [],
            'parsed_keywords' => '',
            'resume_text' => '',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/profile');

        $response->assertStatus(200)
            ->assertJsonPath('id', $profile->id)
            ->assertJsonPath('skills', ['PHP', 'Laravel'])
            ->assertJsonPath('linkedin_url', 'https://linkedin.com/in/example');
    }

    public function test_show_does_not_return_another_users_profile(): void
    {
        $owner = User::factory()->create();
        UserProfile::create([
            'user_id' => $owner->id,
            'skills' => ['PHP'],
            'location' => [],
            'linkedin_url' => '',
            'github_url' => '',
            'portfolio_url' => '',
            'suggested_roles' => [],
            'experience' => [],
            'education' => [],
            'parsed_keywords' => '',
            'resume_text' => '',
        ]);

        $otherUser = User::factory()->create();
        $token = $otherUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/profile');

        $response->assertStatus(404);
    }

    public function test_update_creates_a_profile_when_none_exists_yet(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/profile', [
                'skills' => ['Go', 'Docker'],
                'location' => ['city' => 'Vancouver'],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('skills', ['Go', 'Docker']);

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
        ]);
    }

    public function test_update_persists_changes_to_an_existing_profile(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $profile = UserProfile::create([
            'user_id' => $user->id,
            'skills' => ['PHP'],
            'location' => [],
            'linkedin_url' => 'https://linkedin.com/in/old',
            'github_url' => '',
            'portfolio_url' => '',
            'suggested_roles' => [],
            'experience' => [],
            'education' => [],
            'parsed_keywords' => '',
            'resume_text' => '',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/profile', [
                'skills' => ['PHP', 'Vue'],
                'linkedin_url' => 'https://linkedin.com/in/new',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('skills', ['PHP', 'Vue'])
            ->assertJsonPath('linkedin_url', 'https://linkedin.com/in/new');

        $this->assertSame(1, UserProfile::where('user_id', $user->id)->count());

        $profile->refresh();
        $this->assertSame(['PHP', 'Vue'], $profile->skills);
        $this->assertSame('https://linkedin.com/in/new', $profile->linkedin_url);
    }

    public function test_update_rejects_invalid_url_fields(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/profile', [
                'linkedin_url' => 'not-a-url',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['linkedin_url']);
    }

    public function test_update_rejects_non_array_skills(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/profile', [
                'skills' => 'PHP, Laravel',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['skills']);
    }

    /*
     * The shapes AnalyzeResumeJob::updateUserProfile actually persists must be
     * accepted verbatim, otherwise the frontend cannot round-trip an
     * auto-extracted profile back through PATCH /api/profile.
     */

    public function test_update_accepts_the_shapes_written_by_the_resume_parser(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $payload = [
            'skills' => [
                'primary' => ['PHP', 'Laravel'],
                'secondary' => ['Docker'],
            ],
            'location' => [
                'city' => 'Toronto',
                'state' => 'Ontario',
                'country' => 'Canada',
            ],
            'suggested_roles' => ['Backend Engineer'],
            'experience' => [
                [
                    'company' => 'Acme Inc',
                    'position' => 'Senior Engineer',
                    // The parser writes an integer month count.
                    'duration' => 24,
                    'achievements' => ['Cut build times in half'],
                ],
            ],
            'education' => [
                [
                    'institution' => 'University of Toronto',
                    'degree' => 'BSc',
                    'fieldOfStudy' => 'Computer Science',
                    'startYear' => 2015,
                    'endYear' => 2019,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/profile', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('skills.primary', ['PHP', 'Laravel'])
            ->assertJsonPath('skills.secondary', ['Docker'])
            ->assertJsonPath('location.city', 'Toronto')
            ->assertJsonPath('experience.0.company', 'Acme Inc')
            ->assertJsonPath('experience.0.achievements.0', 'Cut build times in half')
            ->assertJsonPath('education.0.institution', 'University of Toronto');

        $profile = UserProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['primary' => ['PHP', 'Laravel'], 'secondary' => ['Docker']], $profile->skills);
        $this->assertSame(24, $profile->experience[0]['duration']);
        $this->assertSame(2019, $profile->education[0]['endYear']);
    }

    public function test_update_accepts_a_hand_written_duration_string(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/profile', [
                'experience' => [
                    ['company' => 'Acme Inc', 'duration' => '2 years'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('experience.0.duration', '2 years');
    }

    public function test_update_rejects_non_string_entries_inside_a_skill_group(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/profile', [
                'skills' => ['primary' => [['name' => 'PHP']]],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['skills.primary.0']);
    }

    public function test_update_rejects_unknown_skill_groups(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/profile', [
                'skills' => ['primary' => ['PHP'], 'tertiary' => ['COBOL']],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['skills']);
    }

    public function test_update_rejects_unknown_location_keys(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/profile', [
                'location' => ['city' => 'Toronto', 'postcode' => 'M5V'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['location']);
    }

    public function test_update_rejects_a_non_object_experience_entry(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/profile', [
                'experience' => ['Acme Inc'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['experience.0']);
    }

    public function test_update_reports_nested_experience_field_errors_by_path(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/profile', [
                'experience' => [
                    ['company' => 'Fine Co'],
                    ['company' => str_repeat('a', 256)],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['experience.1.company'])
            ->assertJsonMissingValidationErrors(['experience.0.company']);
    }

    public function test_update_rejects_an_out_of_range_education_year(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/profile', [
                'education' => [
                    ['institution' => 'Somewhere', 'startYear' => 'last year'],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['education.0.startYear']);
    }

    public function test_update_stores_a_cleared_url_as_an_empty_string(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        UserProfile::create([
            'user_id' => $user->id,
            'skills' => [],
            'location' => [],
            'linkedin_url' => 'https://linkedin.com/in/example',
            'github_url' => '',
            'portfolio_url' => '',
            'suggested_roles' => [],
            'experience' => [],
            'education' => [],
            'parsed_keywords' => '',
            'resume_text' => '',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/profile', [
                'linkedin_url' => '',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('linkedin_url', '');

        $this->assertSame('', UserProfile::where('user_id', $user->id)->firstOrFail()->linkedin_url);
    }
}
