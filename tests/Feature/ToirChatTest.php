<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ToirChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ToirChatTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code = 'mining'): array
    {
        $company = Company::create(['name' => $code, 'code' => $code]);
        $users = [];
        foreach (['owner', 'engineer', 'technician'] as $role) {
            $u = User::factory()->create();
            $company->users()->attach($u->id, ['role' => $role, 'is_active' => true]);
            $users[] = $u;
        }
        $this->login($company, $users[0]);

        return [$company, ...$users];
    }

    private function login(Company $company, User $user): void
    {
        $this->actingAs($user)->withSession(['toir_company_id' => $company->id]);
    }

    public function test_direct_chat_send_idempotency_delivery_read_reply_and_edit(): void
    {
        [$company,$first,$second] = $this->company();
        $route = '/api/v1/chats';
        $chat = $this->postJson($route, ['kind' => 'direct', 'user_id' => $second->id])->assertCreated()
            ->assertJsonPath('data.kind', 'direct')->json('data');
        $id = $chat['id'];
        $this->postJson($route, ['kind' => 'direct', 'user_id' => $second->id])->assertOk()
            ->assertJsonPath('data.id', $id);
        $message = $this->postJson("$route/$id/messages", [
            'body' => 'Проверь компрессор', 'client_token' => 'send-1',
        ])->assertCreated()->assertJsonPath('data.total_recipients', 1)->json('data');
        $mid = $message['id'];
        $this->postJson("$route/$id/messages", [
            'body' => 'Проверь компрессор', 'client_token' => 'send-1',
        ])->assertOk()->assertJsonPath('data.id', $mid);
        $this->assertSame(1, ToirChatMessage::count());
        $this->patchJson("$route/$id/messages/$mid", [
            'body' => 'Проверь компрессор №1',
        ])->assertOk()->assertJsonPath('data.body', 'Проверь компрессор №1');

        $this->login($company, $second);
        $this->getJson($route)->assertOk()->assertJsonPath('data.0.unread_count', 1);
        $this->getJson("$route/$id/messages")->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("$route/$id/receipts", [
            'kind' => 'delivered', 'messages' => [$mid],
        ])->assertOk()->assertJsonPath('changed', 1);
        $this->postJson("$route/$id/receipts", [
            'kind' => 'read', 'messages' => [$mid],
        ])->assertOk()->assertJsonPath('unread_count', 0);
        $this->postJson("$route/$id/receipts", [
            'kind' => 'read', 'messages' => [$mid],
        ])->assertOk()->assertJsonPath('changed', 0);
        $reply = $this->postJson("$route/$id/messages", [
            'body' => 'Выполняю', 'reply_to_id' => $mid,
        ])->assertCreated()->assertJsonPath('data.reply_preview', 'Проверь компрессор №1')
            ->json('data');
        $this->patchJson("$route/$id/messages/$mid", ['body' => 'Взлом'])->assertForbidden();
        $this->login($company, $first);
        $this->getJson("$route/$id/messages")->assertJsonPath('data.0.read_count', 1);
        $this->postJson("$route/$id/receipts", [
            'kind' => 'read', 'messages' => [$reply['id']],
        ])->assertOk()->assertJsonPath('unread_count', 0);
        $this->deleteJson("$route/$id/messages/$mid")->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->getJson("$route/$id/messages")->assertJsonPath('data.0.body', '');
    }

    public function test_membership_and_company_boundaries_block_cross_tenant_and_non_member(): void
    {
        [$first,$owner,$peer,$notInvited] = $this->company();
        $channel = $this->postJson('/api/v1/chats', [
            'kind' => 'channel', 'title' => 'Ремонтная группа', 'members' => [$peer->id],
        ])->assertCreated()->json('data');
        $id = $channel['id'];
        $this->login($first, $notInvited);
        $this->getJson("/api/v1/chats/$id")->assertForbidden();
        $this->postJson("/api/v1/chats/$id/messages", ['body' => 'Hidden'])->assertForbidden();
        $this->getJson("/api/v1/chats/$id/messages")->assertForbidden();
        $this->postJson("/api/v1/chats/$id/receipts", [
            'kind' => 'read', 'messages' => [123],
        ])->assertForbidden();
        $this->login($first, $peer);
        $this->postJson("/api/v1/chats/$id/members", ['user_id' => $notInvited->id])->assertForbidden();
        $this->postJson("/api/v1/chats/$id/leave")->assertOk();
        $this->getJson("/api/v1/chats/$id")->assertForbidden();
        $this->login($first, $owner);
        $this->postJson("/api/v1/chats/$id/members", ['user_id' => $notInvited->id])->assertOk();
        $this->login($first, $notInvited);
        $this->getJson("/api/v1/chats/$id")->assertOk();

        [$second,$foreign] = $this->company('solutions');
        $this->postJson('/api/v1/chats', [
            'kind' => 'direct', 'user_id' => $owner->id,
        ])->assertStatus(422);
        $this->getJson("/api/v1/chats/$id")->assertNotFound();
    }

    public function test_new_channel_members_cannot_read_or_reply_to_pre_join_messages(): void
    {
        [$company,$owner,$peer] = $this->company();
        $chat = $this->postJson('/api/v1/chats', [
            'kind' => 'channel', 'title' => 'Private maintenance',
        ])->assertCreated()->json('data');
        $id = $chat['id'];
        $old = $this->postJson("/api/v1/chats/$id/messages", [
            'body' => 'Pre-join private note',
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/chats/$id/members", [
            'user_id' => $peer->id,
        ])->assertOk();
        $this->login($company, $peer);
        $this->getJson("/api/v1/chats/$id/messages")->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/chats/$id")->assertOk()
            ->assertJsonPath('data.last_message', null);
        $this->postJson("/api/v1/chats/$id/messages", [
            'body' => 'Guessing old message', 'reply_to_id' => $old['id'],
        ])->assertStatus(422);

        $this->login($company, $owner);
        $this->postJson("/api/v1/chats/$id/messages", [
            'body' => 'Welcome to the channel',
            'reply_to_id' => $old['id'],
        ])->assertCreated();
        $this->login($company, $peer);
        $this->getJson("/api/v1/chats/$id/messages")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.reply_preview', null);
    }

    public function test_archiving_is_per_member_and_direct_chat_unique(): void
    {
        [$company,$owner,$peer] = $this->company();
        $chat = $this->postJson('/api/v1/chats', [
            'kind' => 'direct', 'user_id' => $peer->id,
        ])->assertCreated()->json('data');
        $id = $chat['id'];
        $this->login($company, $peer);
        $this->postJson('/api/v1/chats', ['kind' => 'direct', 'user_id' => $owner->id])
            ->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson("/api/v1/chats/$id/archive")->assertOk()->assertJsonPath('archived', true);
        $this->getJson('/api/v1/chats')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/chats?filter=archived')->assertJsonCount(1, 'data');
        $this->login($company, $owner);
        $this->getJson('/api/v1/chats')->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/chats/$id/leave")->assertStatus(409);
    }

    public function test_no_anonymous_chat_access(): void
    {
        $this->getJson('/api/v1/chats')->assertUnauthorized();
        $this->postJson('/api/v1/chats', ['kind' => 'channel', 'title' => 'Hidden'])->assertUnauthorized();
    }
}
