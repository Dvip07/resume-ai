<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserAutomationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The automation-settings API (App\Http\Controllers\Api\AutomationSettingController).
 *
 * The subject is the LinkedIn opt-in and its risk acknowledgment: the
 * acknowledgment is enforced server-side, so a client that skips the checkbox —
 * or never renders one — cannot switch LinkedIn automation on.
 *
 * Validates: Requirements 9.3, 9.7
 */
class AutomationSettingTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('test-token')->plainTextToken;
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/automation-settings')->assertStatus(401);
        $this->patchJson('/api/automation-settings', [])->assertStatus(401);
    }

    public function test_a_user_with_no_row_reads_the_platform_defaults_without_one_being_written(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson('/api/automation-settings')
            ->assertOk()
            ->assertJsonPath('linkedin_auto_apply_opt_in', false)
            ->assertJsonPath('auto_apply_enabled', false)
            ->assertJsonPath('linkedin.platform_daily_cap', 5)
            ->assertJsonPath('linkedin.opt_in_setting', 'linkedin_auto_apply_opt_in');

        // A read is not a choice.
        $this->assertNull(UserAutomationSetting::query()->where('user_id', $user->id)->first());
    }

    public function test_enabling_linkedin_without_the_acknowledgment_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson('/api/automation-settings', ['linkedin_auto_apply_opt_in' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('linkedin_risk_acknowledged');

        $this->assertFalse(UserAutomationSetting::forUser($user->id)->linkedin_auto_apply_opt_in);
    }

    public function test_enabling_linkedin_with_the_acknowledgment_is_stored(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson('/api/automation-settings', [
                'linkedin_auto_apply_opt_in' => true,
                'linkedin_risk_acknowledged' => true,
            ])
            ->assertOk()
            ->assertJsonPath('linkedin_auto_apply_opt_in', true);

        $settings = UserAutomationSetting::forUser($user->id);
        $this->assertTrue($settings->linkedin_auto_apply_opt_in);
        // The acknowledgment is about one change, not a stored fact.
        $this->assertFalse(in_array('linkedin_risk_acknowledged', array_keys($settings->getAttributes()), true));
    }

    public function test_turning_linkedin_off_needs_no_acknowledgment(): void
    {
        $user = User::factory()->create();
        UserAutomationSetting::updateOrCreate(
            ['user_id' => $user->id],
            ['linkedin_auto_apply_opt_in' => true] + UserAutomationSetting::defaultsFor($user->id),
        );

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson('/api/automation-settings', ['linkedin_auto_apply_opt_in' => false])
            ->assertOk()
            ->assertJsonPath('linkedin_auto_apply_opt_in', false);

        $this->assertFalse(UserAutomationSetting::forUser($user->id)->linkedin_auto_apply_opt_in);
    }

    /**
     * A partial patch leaves the rest alone — in particular it cannot quietly
     * reset the LinkedIn opt-in to the platform default.
     */
    public function test_an_unrelated_patch_preserves_the_linkedin_opt_in(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson('/api/automation-settings', [
                'linkedin_auto_apply_opt_in' => true,
                'linkedin_risk_acknowledged' => true,
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson('/api/automation-settings', ['auto_apply_enabled' => true])
            ->assertOk()
            ->assertJsonPath('auto_apply_enabled', true)
            ->assertJsonPath('linkedin_auto_apply_opt_in', true);
    }

    /** The user may go under the platform's LinkedIn ceiling, never over it. */
    public function test_the_linkedin_cap_cannot_be_raised_above_the_platform_ceiling(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson('/api/automation-settings', ['linkedin_daily_apply_cap' => 50])
            ->assertStatus(422)
            ->assertJsonValidationErrors('linkedin_daily_apply_cap');

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->patchJson('/api/automation-settings', ['linkedin_daily_apply_cap' => 3])
            ->assertOk()
            ->assertJsonPath('linkedin_daily_apply_cap', 3);
    }
}
