<?php

namespace App\Services\Apply;

/**
 * An adapter that a URL match alone is not enough to reach (Requirement 9.3).
 *
 * Every other adapter answers one question — "is this my ATS?" — and that is
 * the whole of its admission policy, because applying to a Greenhouse board is
 * an ordinary, anonymous thing to do on the candidate's behalf. LinkedIn Easy
 * Apply is not: it drives the user's own signed-in LinkedIn account, it is
 * arguably against LinkedIn's terms of service, and the thing at risk is the
 * account itself rather than one failed submission. So it needs a second
 * question — "did this user say yes?" — and that question has an owner (the
 * user), a storage location ({@see \App\Models\UserAutomationSetting}) and a
 * per-user answer, none of which `supports()` can see.
 *
 * Hence this interface rather than an `if` inside the adapter. The gate is
 * enforced at *resolution* time by {@see ApplyAdapterRegistry}, which keeps
 * gated adapters in a separate `apply.gated_registry` map and never even asks
 * them `supports()` unless the opt-in is on. A gated adapter is therefore
 * unreachable by a domain match, by a config typo that lists it in the ordinary
 * registry's position, or by a caller that forgot to pass a user — "no user" is
 * "no consent", not "skip the check".
 *
 * The adapter still re-checks in {@see ApplyAdapter::apply()}: defence in depth
 * costs one database read on a path that is about to open a browser, and the
 * consequence of being wrong is the user's LinkedIn account.
 */
interface OptInGatedApplyAdapter extends ApplyAdapter
{
    /**
     * The `user_automation_settings` column that has to be true. Returned
     * rather than assumed so the audit trail and the settings UI can name the
     * exact flag the user has to flip.
     */
    public function optInSettingName(): string;

    /** Has this user explicitly consented to this adapter running for them? */
    public function isPermittedFor(int $userId): bool;

    /**
     * The user-facing explanation when the gate is shut: what did not happen,
     * and what the user can do about it. Read by
     * {@see \App\Jobs\SubmitApplication} for the `needs_review` note, so it has
     * to make sense on a dashboard row with no other context.
     */
    public function optInRequiredReason(): string;
}
