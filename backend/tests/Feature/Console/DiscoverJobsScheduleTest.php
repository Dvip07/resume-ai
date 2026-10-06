<?php

namespace Tests\Feature\Console;

use App\Console\ScheduleDefinition;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Discovery has to happen without anyone asking for it (Requirements 3.1,
 * 11.1), so "is the command registered on the *live* schedule, at the cadence
 * config asks for" is the real contract — a correct command plus an empty
 * schedule discovers nothing, forever, while looking healthy.
 */
class DiscoverJobsScheduleTest extends TestCase
{
    /**
     * Reads the application's own schedule, not a reconstructed one: this is
     * the assertion that catches a schedule defined somewhere the framework
     * never looks.
     */
    public function test_discover_command_is_registered_on_the_live_schedule(): void
    {
        $event = $this->findDiscoveryEvent($this->app->make(Schedule::class)->events());

        $this->assertNotNull($event, 'jobs:discover is not on the application schedule.');
        $this->assertSame('0 * * * *', $event->expression);
    }

    public function test_cadence_and_timezone_come_from_config(): void
    {
        $event = $this->defineWithConfig([
            'cron' => '*/30 * * * *',
            'timezone' => 'Asia/Kolkata',
        ]);

        $this->assertNotNull($event);
        $this->assertSame('*/30 * * * *', $event->expression);
        $this->assertSame('Asia/Kolkata', $event->timezone);
    }

    public function test_run_is_guarded_against_overlap_and_duplicate_hosts(): void
    {
        $event = $this->defineWithConfig();

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping, 'Discovery dispatch may overlap itself.');
        $this->assertTrue($event->onOneServer, 'Every host would fan out the same users.');
    }

    public function test_discovery_can_be_disabled_from_config(): void
    {
        $this->assertNull($this->defineWithConfig(['enabled' => false]));
    }

    /**
     * Re-runs the definition against a fresh Schedule so config set here is
     * honoured — the application's schedule was built during console bootstrap,
     * before the test could touch config.
     *
     * @param  array<string, mixed>  $discoveryConfig
     */
    private function defineWithConfig(array $discoveryConfig = []): ?Event
    {
        config(['pipeline.discovery' => array_merge(
            config('pipeline.discovery'),
            $discoveryConfig
        )]);

        $schedule = new Schedule();
        (new ScheduleDefinition)($schedule);

        return $this->findDiscoveryEvent($schedule->events());
    }

    /**
     * @param  array<int, Event>  $events
     */
    private function findDiscoveryEvent(array $events): ?Event
    {
        foreach ($events as $event) {
            if (str_contains($event->command ?? '', 'jobs:discover')) {
                return $event;
            }
        }

        return null;
    }
}
