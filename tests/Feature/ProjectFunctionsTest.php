<?php

use App\Models\ChatMessage;
use App\Models\Disease;
use App\Models\RiceScan;
use App\Models\User;
use App\Services\GoogleIdTokenVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('registers farmers and supports password login and bearer logout', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Test Farmer', 'email' => 'farmer@gmail.com',
        'password' => 'password', 'password_confirmation' => 'password', 'role' => 'admin',
    ])->assertCreated()->assertJsonPath('user.role', 'farmer');
    $login = $this->postJson('/api/v1/auth/login', ['email' => 'farmer@gmail.com', 'password' => 'password'])
        ->assertOk();
    $this->withToken($login->json('token'))->postJson('/api/v1/auth/logout')->assertOk();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('logs out session users even when Sanctum uses a transient token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('browser')->plainTextToken;
    $this->actingAs($user, 'web')->withHeader('Referer', 'http://localhost')->withToken($token)
        ->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('success', true);
    $this->assertGuest('web');
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('rejects Google account details without a verified token', function () {
    User::factory()->create(['email' => 'admin@gmail.com', 'role' => 'admin']);
    $this->postJson('/api/v1/auth/google-login', ['email' => 'admin@gmail.com', 'name' => 'Admin'])
        ->assertUnprocessable();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->getJson('/api/v1/auth/google-accounts')->assertOk()->assertExactJson(['success' => true, 'accounts' => []]);
});

it('uses only verified Google claims when signing in', function () {
    $user = User::factory()->create(['email' => 'farmer@gmail.com', 'role' => 'farmer']);
    $this->mock(GoogleIdTokenVerifier::class)->shouldReceive('verify')->once()->with('verified-token')->andReturn([
        'email' => $user->email, 'sub' => 'google-subject', 'name' => $user->name,
    ]);
    $this->postJson('/api/v1/auth/google-login', [
        'id_token' => 'verified-token', 'email' => 'admin@gmail.com',
    ])->assertOk()->assertJsonPath('user.id', $user->id);
});

it('reports failed password reset email delivery and removes the unusable code', function () {
    User::factory()->create(['email' => 'farmer@gmail.com']);
    Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP unavailable'));
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'farmer@gmail.com'])
        ->assertStatus(503)->assertJsonPath('success', false);
    $this->assertDatabaseCount('password_reset_tokens', 0);
});

it('resets a password with a valid code and consumes the code', function () {
    $user = User::factory()->create(['email' => 'farmer@gmail.com']);
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => '123456', 'created_at' => now()]);
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $user->email, 'token' => '123456',
        'password' => 'new-password', 'password_confirmation' => 'new-password',
    ])->assertOk()->assertJsonPath('success', true);
    expect(\Illuminate\Support\Facades\Hash::check('new-password', $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('password_reset_tokens', 0);
});

it('includes each calendar month and sheath blight in reports at month end', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-03-31 12:00:00'));
    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    RiceScan::create(['image_path' => 'scans/test.jpg', 'disease_name' => 'Sheath Blight', 'confidence' => null, 'severity' => 'unassessed']);
    $response = $this->getJson('/api/v1/admin/reports?period=monthly')->assertOk();
    expect(array_column($response->json('data.trends'), 'label'))
        ->toBe(['Oct 2025', 'Nov 2025', 'Dec 2025', 'Jan 2026', 'Feb 2026', 'Mar 2026']);
    $response->assertJsonPath('data.disease_frequency.Sheath Blight', 1);
    $this->travelBack();
});

it('keeps consultation history isolated between users', function () {
    $owner = User::factory()->create(['role' => 'farmer']);
    $other = User::factory()->create(['role' => 'farmer']);
    foreach ([$owner, $other] as $user) {
        ChatMessage::create(['user_id' => $user->id, 'role' => 'user', 'content' => 'My rice field', 'language' => 'english']);
    }
    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/consultation/messages')->assertOk()->assertJsonCount(1, 'messages');
    $this->deleteJson('/api/v1/consultation/clear')->assertOk();
    $this->assertDatabaseHas('chat_messages', ['user_id' => $other->id]);
    $this->assertDatabaseMissing('chat_messages', ['user_id' => $owner->id]);
    $this->getJson('/api/v1/admin/users')->assertForbidden();
    $this->getJson('/api/v1/staff/overview')->assertForbidden();
});

it('applies disease management edits and status changes to treatment guides', function () {
    Disease::create(['name' => 'Hidden Blast', 'code' => 'blast', 'status' => 'inactive']);
    Disease::create(['name' => 'Updated BLB', 'code' => 'blb', 'status' => 'active', 'chemical_treatments' => [], 'organic_treatments' => []]);
    Disease::create(['name' => 'Custom Disease', 'code' => 'custom', 'status' => 'active']);
    $response = $this->getJson('/api/v1/treatment-guides')->assertOk();
    $guides = collect($response->json('data'))->keyBy('key');
    expect($guides->has('blast'))->toBeFalse()
        ->and($guides['blb']['name'])->toBe('Updated BLB')
        ->and($guides['blb']['treatments'])->toBe(['chemical' => [], 'organic' => []])
        ->and($guides['custom']['name'])->toBe('Custom Disease');
});

it('updates a profile without allowing a role change', function () {
    $user = User::factory()->create(['role' => 'farmer']);
    Sanctum::actingAs($user);
    $this->putJson('/api/v1/auth/profile', ['name' => 'Updated Farmer', 'location' => 'Roxas', 'role' => 'admin'])
        ->assertOk()->assertJsonPath('user.name', 'Updated Farmer')->assertJsonPath('user.role', 'farmer');
    expect($user->fresh()->location)->toBe('Roxas');
});

it('isolates scan history, dashboard counts and deletion by owner', function () {
    $owner = User::factory()->create(['role' => 'farmer']);
    $other = User::factory()->create(['role' => 'farmer']);
    $attributes = ['image_path' => 'data:image/png;base64,AA==', 'disease_name' => 'Healthy Rice Leaf', 'confidence' => null, 'severity' => 'healthy'];
    $ownScan = RiceScan::create($attributes + ['user_id' => $owner->id]);
    $otherScan = RiceScan::create($attributes + ['user_id' => $other->id]);
    $this->getJson('/api/v1/dashboard/public-stats')->assertOk()->assertJsonCount(0, 'recent_scans');
    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/rice-detector/history')->assertOk()->assertJsonPath('stats.total', 1);
    $this->getJson('/api/v1/dashboard/stats')->assertOk()->assertJsonPath('stats.total_scans', 1);
    $this->deleteJson('/api/v1/rice-detector/scan/'.$otherScan->id)->assertNotFound();
    $this->deleteJson('/api/v1/rice-detector/scan/'.$ownScan->id)->assertOk();
    $this->assertDatabaseHas('scans', ['id' => $otherScan->id]);
    $this->getJson('/api/v1/rice-detector/history')->assertOk()->assertJsonPath('stats.total', 0);
});
