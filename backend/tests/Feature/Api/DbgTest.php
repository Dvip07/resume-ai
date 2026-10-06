<?php
namespace Tests\Feature\Api;
use App\Jobs\DiscoverJobsForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
class DbgTest extends TestCase {
  use RefreshDatabase;
  public function test_dbg(): void {
    Queue::fake();
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
      ->postJson('/api/jobs/discover', ['roles' => ['SRE'], 'location' => 'Remote', 'limit' => 10])
      ->assertStatus(202);
    Queue::assertPushed(DiscoverJobsForUser::class, function ($job) {
      dump(['roles' => $job->roles, 'loc' => $job->location, 'limit' => $job->limit]);
      return true;
    });
  }
}
