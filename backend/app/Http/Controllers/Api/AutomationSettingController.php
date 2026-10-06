<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserAutomationSetting;
use App\Services\Apply\Adapters\LinkedInEasyApplyAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The authenticated user's automation consent and throttle settings
 * (Requirements 9.1, 9.3, 9.7).
 *
 * The minimum surface task 15.9 needs: the LinkedIn Easy Apply opt-in has to be
 * something a user can actually turn on, and turning it on has to be a
 * deliberate act rather than a stray tap on a switch.
 *
 * ## The risk acknowledgment is enforced here, not only in the UI
 *
 * Switching `linkedin_auto_apply_opt_in` from off to on requires
 * `linkedin_risk_acknowledged: true` in the same request. A checkbox in the
 * frontend is a nice affordance and nothing more — it is a client, and clients
 * can be replaced by a curl command. The consequence of a careless opt-in is
 * the restriction of the user's own LinkedIn account, so the server insists on
 * seeing the acknowledgment and rejects the change with a 422 otherwise.
 *
 * The acknowledgment is not stored. It is a statement about *this* change, not
 * a standing fact: turning the setting off and on again should ask again.
 *
 * Switching it **off** never requires anything. Withdrawing consent must be the
 * easiest operation on this screen.
 *
 * A user with no row gets the platform defaults on read
 * ({@see UserAutomationSetting::forUser()}, everything off) without one being
 * written, so a GET never looks like a choice the user has made.
 */
class AutomationSettingController extends Controller
{
    /** Proof that the user has read the LinkedIn warning. Never persisted. */
    public const ACKNOWLEDGMENT = 'linkedin_risk_acknowledged';

    public function show(Request $request): JsonResponse
    {
        return response()->json(
            $this->payload(UserAutomationSetting::forUser((int) $request->user()->id))
        );
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'auto_apply_enabled' => ['sometimes', 'boolean'],
            'auto_apply_star_threshold' => ['sometimes', 'integer', 'between:1,5'],
            'linkedin_auto_apply_opt_in' => ['sometimes', 'boolean'],
            'daily_apply_cap' => ['sometimes', 'integer', 'between:0,100'],
            'linkedin_daily_apply_cap' => ['sometimes', 'integer', 'between:0,'.$this->linkedInCeiling()],
            self::ACKNOWLEDGMENT => ['sometimes', 'boolean'],
        ], [
            'linkedin_daily_apply_cap.between' => 'LinkedIn applications are capped at '.$this->linkedInCeiling()
                .' a day to keep the account out of trouble.',
        ]);

        $userId = (int) $request->user()->id;
        $current = UserAutomationSetting::forUser($userId);

        $turningLinkedInOn = ($validated['linkedin_auto_apply_opt_in'] ?? false) === true
            && $current->linkedin_auto_apply_opt_in !== true;

        if ($turningLinkedInOn && ($validated[self::ACKNOWLEDGMENT] ?? false) !== true) {
            // Deliberately a validation error on the acknowledgment field: the
            // frontend shows it next to the checkbox the user has to tick.
            throw ValidationException::withMessages([
                self::ACKNOWLEDGMENT => 'Acknowledge the LinkedIn automation risks before enabling this.',
            ]);
        }

        unset($validated[self::ACKNOWLEDGMENT]);

        $settings = UserAutomationSetting::updateOrCreate(
            ['user_id' => $userId],
            // Defaults first so a brand-new row is complete, then the request's
            // own values, then whatever the user had already chosen for the
            // fields this request did not mention.
            array_merge(
                UserAutomationSetting::defaultsFor($userId),
                $current->exists ? $current->only(array_keys(UserAutomationSetting::defaultsFor($userId))) : [],
                $validated,
            ),
        );

        return response()->json($this->payload($settings));
    }

    /**
     * The settings, plus the context the screen needs to explain them: the
     * platform's own LinkedIn ceiling, and the flag name the apply pipeline
     * actually checks.
     *
     * @return array<string, mixed>
     */
    private function payload(UserAutomationSetting $settings): array
    {
        return [
            'auto_apply_enabled' => (bool) $settings->auto_apply_enabled,
            'auto_apply_star_threshold' => (int) $settings->auto_apply_star_threshold,
            'linkedin_auto_apply_opt_in' => (bool) $settings->linkedin_auto_apply_opt_in,
            'daily_apply_cap' => (int) $settings->daily_apply_cap,
            'linkedin_daily_apply_cap' => (int) $settings->linkedin_daily_apply_cap,
            'linkedin' => [
                'opt_in_setting' => LinkedInEasyApplyAdapter::OPT_IN,
                // The platform cap the user cannot raise, shown so the number
                // on the screen is the number that will actually apply.
                'platform_daily_cap' => $this->linkedInCeiling(),
                'acknowledgment_required' => true,
            ],
        ];
    }

    /** The platform's LinkedIn ceiling; a user may go lower, never higher. */
    private function linkedInCeiling(): int
    {
        $cap = config('apply.adapters.'.LinkedInEasyApplyAdapter::KEY.'.daily_cap', 5);

        return is_numeric($cap) ? max(0, (int) $cap) : 5;
    }
}
