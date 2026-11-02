<?php

namespace Tests\Feature;

use App\Events\ToirChatSignal;
use App\Models\Company;
use App\Models\ToirChat;
use App\Models\ToirChatMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class ToirRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code): array
    {
        $c=Company::create(['name'=>$code,'code'=>$code]);
        $a=User::factory()->create();
        $b=User::factory()->create();
        $c->users()->attach($a->id,['role'=>'owner','is_active'=>true]);
        $c->users()->attach($b->id,['role'=>'technician','is_active'=>true]);
        return [$c,$a,$b];
    }

    private function login(Company $c,User $u): void
    {
        $this->actingAs($u)->withSession(['toir_company_id'=>$c->id]);
    }

    private function configureBroadcasting(): void
    {
        config([
            'broadcasting.default'=>'reverb',
            'broadcasting.connections.reverb'=>[
                'driver'=>'reverb',
                'key'=>'test-public-key',
                'secret'=>'test-private-secret',
                'app_id'=>'test-app',
                'options'=>[
                    'host'=>'127.0.0.1','port'=>18123,
                    'scheme'=>'http','useTLS'=>false,
                ],
            ],
        ]);
        require base_path('routes/channels.php');
    }

    public function test_private_user_channel_requires_authenticated_active_company_membership(): void
    {
        [$c,$a,$b]=$this->company('mining');
        [$foreign,$x]=$this->company('solutions');
        $this->configureBroadcasting();
        $channel='private-toir.company.'.$c->id.'.user.'.$a->id;
        $presence='presence-toir.company.'.$c->id.'.presence';
        $payload=['socket_id'=>'1234.5678','channel_name'=>$channel];

        $this->postJson('/api/v1/broadcasting/auth',$payload)->assertUnauthorized();
        $this->login($c,$a);
        $this->assertTrue((bool)$a->fresh()->is_active);
        $this->assertTrue($a->activeCompanies()->whereKey($c->id)->exists());
        $this->assertTrue(\Illuminate\Support\Facades\Broadcast::driver()->getChannels()->has('toir.company.{companyId}.user.{userId}'));
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/broadcasting/auth',$payload)
            ->assertOk()->assertJsonStructure(['auth']);
        $this->postJson('/api/v1/broadcasting/auth',[
            'socket_id'=>'1234.5678',
            'channel_name'=>'private-toir.company.'.$c->id.'.user.'.$b->id,
        ])->assertForbidden();
        $this->postJson('/api/v1/broadcasting/auth',[
            'socket_id'=>'1234.5678',
            'channel_name'=>'private-toir.company.'.$foreign->id.'.user.'.$a->id,
        ])->assertForbidden();
        $this->postJson('/api/v1/broadcasting/auth',[
            'socket_id'=>'1234.5678',
            'channel_name'=>$presence,
        ])->assertOk()->assertJsonStructure(['auth','channel_data']);
        $this->getJson('/api/v1/realtime/config')->assertOk()
            ->assertJsonPath('enabled',true)->assertJsonPath('key','test-public-key')
            ->assertJsonPath('channel','toir.company.'.$c->id.'.user.'.$a->id)
            ->assertJsonMissing(['test-private-secret']);

        $c->users()->updateExistingPivot($a->id,['is_active'=>false]);
        $this->postJson('/api/v1/broadcasting/auth',$payload)->assertForbidden();
    }

    public function test_outbox_is_committed_with_message_and_replayed_once(): void
    {
        [$c,$a,$b]=$this->company('mining');
        $this->login($c,$a);
        $chat=$this->postJson('/api/v1/chats',[
            'kind'=>'direct','user_id'=>$b->id,
        ])->assertCreated()->json('data');
        $id=$chat['id'];
        $this->postJson("/api/v1/chats/$id/messages",[
            'body'=>'Check the pump','client_token'=>'exactly-once-1',
        ])->assertCreated();
        $this->assertDatabaseCount('toir_chat_outbox',2);
        $this->postJson("/api/v1/chats/$id/messages",[
            'body'=>'Check the pump','client_token'=>'exactly-once-1',
        ])->assertOk();
        $this->assertDatabaseCount('toir_chat_outbox',2);
        $this->postJson("/api/v1/chats/$id/messages",[
            'body'=>'Different payload','client_token'=>'exactly-once-1',
        ])->assertStatus(409);

        Event::fake([ToirChatSignal::class]);
        $this->artisan('toir:chat-outbox',['--once'=>true])->assertExitCode(0);
        $this->artisan('toir:chat-outbox',['--once'=>true])->assertExitCode(0);
        $this->assertDatabaseCount('toir_chat_outbox',0);
        Event::assertDispatched(ToirChatSignal::class,2);
        Event::assertDispatched(ToirChatSignal::class,function(ToirChatSignal $event)use($c,$a,$b){
            return $event->kind==='message' && $event->companyId===$c->id &&
                count($event->recipients)===2 &&
                in_array($a->id,$event->recipients,true) &&
                in_array($b->id,$event->recipients,true);
        });
    }

    public function test_member_departure_prevents_future_deliveries_to_ex_member(): void
    {
        [$c,$a,$b]=$this->company('mining');
        $this->login($c,$a);
        $chat=$this->postJson('/api/v1/chats',[
            'kind'=>'channel','title'=>'Repairs','members'=>[$b->id],
        ])->assertCreated()->json('data');
        $id=$chat['id'];
        DB::table('toir_chat_outbox')->delete();
        $this->login($c,$b);
        $this->postJson("/api/v1/chats/$id/leave")->assertOk();
        $this->login($c,$a);
        $this->postJson("/api/v1/chats/$id/messages",['body'=>'Post-leave'])->assertCreated();
        DB::table('toir_chat_outbox')->where('kind','member_left')->delete();

        Event::fake([ToirChatSignal::class]);
        $this->artisan('toir:chat-outbox',['--once'=>true])->assertExitCode(0);
        Event::assertDispatched(ToirChatSignal::class,function(ToirChatSignal $event)use($a,$b){
            return $event->kind==='message' && $event->recipients===[$a->id]
                && !in_array($b->id,$event->recipients,true);
        });
    }

    public function test_typing_is_access_controlled_and_audited(): void
    {
        [$c,$a,$b]=$this->company('mining');
        $u=User::factory()->create();
        $c->users()->attach($u->id,['role'=>'viewer','is_active'=>true]);
        $this->login($c,$a);
        $chat=$this->postJson('/api/v1/chats',['kind'=>'direct','user_id'=>$b->id])->assertCreated()->json('data');
        DB::table('toir_chat_outbox')->delete();
        $this->postJson('/api/v1/chats/'.$chat['id'].'/typing')->assertOk();
        $this->assertDatabaseHas('toir_chat_outbox',['kind'=>'typing','chat_id'=>$chat['id']]);
        $this->login($c,$u);
        $this->postJson('/api/v1/chats/'.$chat['id'].'/typing')->assertForbidden();
    }
}
