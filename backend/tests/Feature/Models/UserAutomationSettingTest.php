<?php

namespace Tests\Feature\Models;

use App\Models\User;
use App\Models\UserAutomationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `user_automation_settings` table and its model: that automation is off
 * until a user opts in, that stored settings override the platform defaults,
 * and that the auto-apply gate needs both a high rating and the toggle.
 *
 * Validates: Requirements 5.2, 9.1, 9.3, 9.7, 1C.1, 1C.7
 */
class UserAutomationSettingTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Dev Patel',
            'email' => 'dev'.uniqid().'@example.com',
            'password' => 'password',
        ]);
    }

    public function test_settings_round_trip_with_their_casts_and_belong_to_the_user(): void
    {
        $user = $this->user();

        UserAutomationSetting::create([
            'user_id' => $user->id,
            'auto_apply_enabled' => true,
            'auto_apply_star_threshold' => 5,
            'linkedin_auto_apply_opt_in' => true,
            'daily_apply_cap' => 30,
            'linkedin_daily_apply_cap' => 3,
        ]);

        $stored = UserAutomationSetting::forUser($user->id);

        $this->assertTrue($stored->auto_apply_enabled);
        $this->assertSame(5, $stored->auto_apply_star_threshold);
        $this->assertTrue($stored->linkedin_auto_apply_opt_in);
        $this->assertSame(30, $stored->daily_apply_cap);
        $this->assertSame(3, $stored->linkedin_daily_apply_cap);
        $this->assertSame($user->id, $stored->user->id);
        $this->assertSame($stored->id, $user->fresh()->automationSettings->id);
    }

    public function test_the_database_defaults_leave_every_automation_flag_off(): void
    {
        $user = $this->user();

        $stored = UserAutomationSetting::create(['user_id' => $user->id])->fresh();

        $this->assertFalse($stored->auto_apply_enabled);
        $this->assertFalse($stored->linkedin_auto_apply_opt_in);
        $this->assertSame(4, $stored->auto_apply_star_threshold);
        $this->assertSame(20, $stored->daily_apply_cap);
        $this->assertSame(5, $stored->linkedin_daily_apply_cap);
    }

    public function test_for_user_returns_config_defaults_without_persisting_a_row(): void
    {
        $user = $this->user();

        config([
            'pipeline.daily_apply_cap' => 11,
            'pipeline.linkedin_daily_apply_cap' => 2,
            'scoring.auto_apply_star_threshold' => 3,
        ]);

        $settings = UserAutomationSetting::forUser($user->id);

        $this->assertFalse($settings->exists, 'reading settings must not claim the user opted in');
        $this->assertSame(0, UserAutomationSetting::query()->count());
        $this->assertFalse($settings->auto_apply_enabled);
        $this->assertFalse($settings->linkedin_auto_apply_opt_in);
        $this->assertSame(3, $settings->auto_apply_star_threshold);
        $this->assertSame(11, $settings->daily_apply_cap);
        $this->assertSame(2, $settings->linkedin_daily_apply_cap);
    }

    public function test_a_user_may_only_have_one_settings_row(): void
    {
        $user = $this->user();

        UserAutomationSetting::create(['user_id' => $user->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        UserAutomationSetting::create(['user_id' => $user->id]);
    }

    public function test_auto_apply_requires_both_the_toggle_and_a_qualifying_rating(): void
    {
        $enabled = new UserAutomationSetting([
            'auto_apply_enabled' => true,
            'auto_apply_star_threshold' => 4,
        ]);

        $this->assertTrue($enabled->allowsAutoApplyAt(4));
        $this->assertTrue($enabled->allowsAutoApplyAt(5));
        $this->assertFalse($enabled->allowsAutoApplyAt(3));

        $disabled = new UserAutomationSetting([
            'auto_apply_enabled' => false,
            'auto_apply_star_threshold' => 4,
        ]);

        $this->assertFalse(
            $disabled->allowsAutoApplyAt(5),
            'a perfect rating must not bypass the user toggle'
        );
    }

    public function test_linkedin_applications_use_their_own_lower_daily_cap(): void
    {
        $settings = new UserAutomationSetting([
            'daily_apply_cap' => 20,
            'linkedin_daily_apply_cap' => 5,
        ]);

        $this->assertSame(20, $settings->dailyCapFor(false));
        $this->assertSame(5, $settings->dailyCapFor(true));
    }
}
