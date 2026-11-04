<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ToirChat;
use App\Models\ToirChatMember;
use App\Models\ToirChatMessage;
use App\Models\ToirChatReceipt;
use App\Models\User;
use App\Services\ToirChatDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ToirChatController extends Controller
{
    private function company(Request $request): Company
    {
        $company = $request->user()->activeCompanies()
            ->whereKey($request->session()->get('toir_company_id'))->first();
        abort_unless($company, 403, 'Нет доступа к компании.');

        return $company;
    }

    private function member(Company $company, int $chatId, User $user): ToirChatMember
    {
        $m = ToirChatMember::where('company_id', $company->id)->where('chat_id', $chatId)
            ->where('user_id', $user->id)->whereNull('left_at')->first();
        abort_unless($m, 403, 'Нет доступа к этой переписке.');

        return $m;
    }

    private function chat(Company $company, int $id, User $user): array
    {
        $chat = ToirChat::where('company_id', $company->id)->findOrFail($id);

        return [$chat, $this->member($company, $chat->id, $user)];
    }

    private function safeUser(Company $company, int $userId): void
    {
        abort_unless($company->users()->whereKey($userId)
            ->wherePivot('is_active', true)->where('users.is_active', true)->exists(),
            422, 'Пользователь не входит в эту компанию.');
    }

    private function messageData(ToirChatMessage $msg, ?int $joinedAfterId = null): array
    {
        $msg->loadMissing(['user:id,name', 'reply:id,chat_id,body,deleted_at,created_at']);
        $receipts = $msg->receipts()->get(['delivered_at', 'read_at']);

        return [
            'id' => $msg->id, 'chat_id' => $msg->chat_id, 'user_id' => $msg->user_id,
            'sender_name' => $msg->user?->name ?? 'Сотрудник',
            'body' => $msg->deleted_at ? '' : $msg->body,
            'reply_to_id' => $msg->reply_to_id,
            'reply_preview' => $msg->reply && ! $msg->reply->deleted_at &&
                ($joinedAfterId === null || $msg->reply->id > $joinedAfterId)
                ? mb_substr($msg->reply->body, 0, 120) : null,
            'deleted' => $msg->deleted_at !== null,
            'edited_at' => $msg->edited_at,
            'created_at' => $msg->created_at,
            'total_recipients' => $receipts->count(),
            'delivered_count' => $receipts->filter(fn ($x) => $x->delivered_at !== null)->count(),
            'read_count' => $receipts->filter(fn ($x) => $x->read_at !== null)->count(),
        ];
    }

    private function row(ToirChat $chat, int $userId): array
    {
        $members = $chat->members()->whereNull('left_at')->with('user:id,name')->get();
        $other = $members->first(fn ($m) => $m->user_id !== $userId);
        $myMember = $members->firstWhere('user_id', $userId);
        $last = $chat->messages()->where('id', '>', (int) ($myMember?->joined_after_message_id ?? 0))
            ->latest('id')->first();
        $unread = ToirChatReceipt::where('company_id', $chat->company_id)
            ->where('chat_id', $chat->id)->where('user_id', $userId)
            ->whereNull('read_at')->count();

        return [
            'id' => $chat->id, 'kind' => $chat->kind,
            'title' => $chat->kind === 'direct' ? ($other?->user?->name ?? 'Личный чат') : $chat->title,
            'is_admin' => (bool) $members->firstWhere('user_id', $userId)?->is_admin,
            'archived' => $chat->members()->where('user_id', $userId)->whereNotNull('archived_at')->exists(),
            'members' => $members->map(fn ($m) => [
                'id' => $m->user_id, 'name' => $m->user?->name ?? 'Сотрудник',
                'is_admin' => (bool) $m->is_admin,
            ])->values()->all(),
            'unread_count' => $unread,
            'last_message' => $last ? [
                'body' => $last->deleted_at ? 'Сообщение удалено' : mb_substr($last->body, 0, 140),
                'created_at' => $last->created_at, 'user_id' => $last->user_id,
            ] : null,
            'updated_at' => $chat->updated_at,
        ];
    }

    public function users(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $people = $company->users()->wherePivot('is_active', true)->where('users.is_active', true)
            ->orderBy('name')->get(['users.id', 'users.name']);

        return response()->json(['data' => $people]);
    }

    public function index(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $id = $request->user()->id;
        $q = ToirChat::where('company_id', $company->id)
            ->whereHas('members', fn ($m) => $m->where('user_id', $id)->whereNull('left_at'));
        $filter = (string) $request->query('filter', 'all');
        if (in_array($filter, ['channels', 'direct'], true)) {
            $q->where('kind', $filter === 'channels' ? 'channel' : 'direct');
        }
        if ($filter === 'archived') {
            $q->whereHas('members', fn ($m) => $m->where('user_id', $id)->whereNotNull('archived_at'));
        } else {
            $q->whereHas('members', fn ($m) => $m->where('user_id', $id)->whereNull('archived_at'));
        }
        $chats = $q->orderByDesc('updated_at')->limit(100)->get();

        return response()->json(['data' => $chats->map(fn ($c) => $this->row($c, $id))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $input = $request->validate([
            'kind' => ['required', Rule::in(['direct', 'channel'])],
            'title' => ['required_if:kind,channel', 'nullable', 'string', 'max:150'],
            'user_id' => ['required_if:kind,direct', 'nullable', 'integer'],
            'members' => ['sometimes', 'array', 'max:30'],
            'members.*' => ['required', 'integer', 'distinct'],
        ]);
        $user = $request->user();
        $channel = $input['kind'] === 'channel';
        if (! $channel) {
            $peer = (int) $input['user_id'];
            abort_if($peer === $user->id, 422, 'Выберите другого сотрудника.');
            $this->safeUser($company, $peer);
        } else {
            foreach ($input['members'] ?? [] as $peer) {
                $this->safeUser($company, (int) $peer);
            }
        }
        $result = DB::transaction(function () use ($company, $input, $user, $channel) {
            if (! $channel) {
                $pair = [$user->id, (int) $input['user_id']];
                sort($pair, SORT_NUMERIC);
                $key = implode(':', $pair);
                $existing = ToirChat::where('company_id', $company->id)->where('direct_key', $key)->first();
                if ($existing) {
                    $m = $existing->members()->where('user_id', $user->id)->first();
                    if ($m && $m->left_at) {
                        $m->left_at = null;
                        $m->save();
                    }

                    return [$existing, false];
                }
            }
            $chat = new ToirChat;
            $chat->company_id = $company->id;
            $chat->created_by = $user->id;
            $chat->kind = $input['kind'];
            $chat->title = $channel ? trim($input['title']) : null;
            $chat->direct_key = $channel ? null : $key;
            $chat->save();
            $ids = $channel ? array_unique(array_merge([$user->id], $input['members'] ?? [])) :
                [$user->id, (int) $input['user_id']];
            foreach ($ids as $id) {
                $member = new ToirChatMember;
                $member->company_id = $company->id;
                $member->chat_id = $chat->id;
                $member->user_id = $id;
                $member->is_admin = $id === $user->id;
                $member->save();
            }
            ToirChatDeliveryService::changed($chat, 'chat_created', null, ['creator_id' => $user->id]);

            return [$chat, true];
        }, 3);

        return response()->json(['data' => $this->row($result[0], $user->id)], $result[1] ? 201 : 200);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        [$chat] = $this->chat($company, $id, $request->user());

        return response()->json(['data' => $this->row($chat, $request->user()->id)]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        [, $member] = $this->chat($company, $id, $request->user());
        $member->archived_at = $member->archived_at ? null : now();
        DB::transaction(function () use ($member) {
            $member->save();
            ToirChatDeliveryService::changed($member->chat, 'archived', null, ['user_id' => $member->user_id]);
        });

        return response()->json(['archived' => (bool) $member->archived_at]);
    }

    public function leave(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        [$chat,$member] = $this->chat($company, $id, $request->user());
        abort_unless($chat->kind === 'channel', 409, 'Нельзя выйти из личного чата.');
        DB::transaction(function () use ($member, $chat) {
            $member->left_at = now();
            $member->save();
            ToirChatDeliveryService::changed($chat, 'member_left', null, ['user_id' => $member->user_id]);
        });

        return response()->json(['message' => 'Вы вышли из канала.']);
    }

    public function addMember(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        [$chat,$me] = $this->chat($company, $id, $request->user());
        abort_unless($chat->kind === 'channel' && $me->is_admin, 403, 'Только администратор канала управляет участниками.');
        $input = $request->validate(['user_id' => ['required', 'integer']]);
        $userId = (int) $input['user_id'];
        $this->safeUser($company, $userId);
        $m = $chat->members()->where('user_id', $userId)->first();
        if ($m) {
            if ($m->left_at) {
                $m->joined_at = now();
                $m->joined_after_message_id = (int) ($chat->messages()->max('id') ?? 0);
                ToirChatReceipt::where('chat_id', $id)->where('user_id', $userId)
                    ->whereNull('read_at')->update(['delivered_at' => now(), 'read_at' => now()]);
            }
            $m->left_at = null;
            $m->archived_at = null;
            $m->save();
        } else {
            $m = new ToirChatMember;
            $m->company_id = $company->id;
            $m->chat_id = $id;
            $m->user_id = $userId;
            $m->is_admin = false;
            $m->joined_after_message_id = (int) ($chat->messages()->max('id') ?? 0);
            $m->save();
        }
        ToirChatDeliveryService::changed($chat, 'member_added', null, ['user_id' => $userId]);

        return response()->json(['data' => $this->row($chat, $request->user()->id)]);
    }

    public function typing(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        [$chat] = $this->chat($company, $id, $request->user());
        ToirChatDeliveryService::changed($chat, 'typing', null, [
            'user_id' => $request->user()->id,
            'expires' => now()->addSeconds(4)->timestamp,
        ]);

        return response()->json(['ok' => true]);
    }

    public function messages(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        [$chat,$member] = $this->chat($company, $id, $request->user());
        $after = max(0, (int) $request->query('after', 0));
        $before = max(0, (int) $request->query('before', 0));
        abort_if($after && $before, 422, 'Укажите только один курсор.');
        $q = $chat->messages()->where('company_id', $company->id)
            ->where('id', '>', (int) $member->joined_after_message_id);
        if ($after) {
            $data = $q->where('id', '>', $after)->orderBy('id')->limit(100)->get();
        } else {
            if ($before) {
                $q->where('id', '<', $before);
            }
            $data = $q->orderByDesc('id')->limit(60)->get()->reverse()->values();
        }

        return response()->json(['data' => $data->map(fn ($m) => $this->messageData($m, (int) $member->joined_after_message_id))->all()]);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        [$chat,$member] = $this->chat($company, $id, $request->user());
        $input = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:2048'],
            'client_token' => ['nullable', 'string', 'max:64'],
            'reply_to_id' => ['nullable', 'integer'],
        ]);
        $body = trim($input['body']);
        abort_if($body === '', 422, 'Нельзя отправить пустое сообщение.');
        $data = DB::transaction(function () use ($request, $company, $chat, $member, $input, $body) {
            if (! empty($input['client_token'])) {
                $existing = $chat->messages()->where('user_id', $request->user()->id)
                    ->where('client_token', $input['client_token'])->first();
                if ($existing) {
                    abort_if($existing->body !== $body ||
                        (int) $existing->reply_to_id !== (int) ($input['reply_to_id'] ?? 0), 409,
                        'Токен сообщения повторно использован с другим содержимым.');

                    return [$existing, false];
                }
            }
            if (! empty($input['reply_to_id'])) {
                abort_unless(ToirChatMessage::where('company_id', $company->id)
                    ->where('chat_id', $chat->id)->whereNull('deleted_at')
                    ->where('id', '>', (int) $member->joined_after_message_id)
                    ->whereKey($input['reply_to_id'])->exists(), 422, 'Исходное сообщение недоступно.');
            }
            $msg = new ToirChatMessage;
            $msg->company_id = $company->id;
            $msg->chat_id = $chat->id;
            $msg->user_id = $request->user()->id;
            $msg->body = $body;
            $msg->client_token = $input['client_token'] ?? null;
            $msg->reply_to_id = $input['reply_to_id'] ?? null;
            $msg->save();
            $recipients = $chat->members()->whereNull('left_at')
                ->where('user_id', '!=', $request->user()->id)->pluck('user_id');
            foreach ($recipients as $recipient) {
                $receipt = new ToirChatReceipt;
                $receipt->company_id = $company->id;
                $receipt->chat_id = $chat->id;
                $receipt->message_id = $msg->id;
                $receipt->user_id = $recipient;
                $receipt->save();
            }
            $chat->touch();
            ToirChatDeliveryService::changed($chat, 'message', $msg, ['user_id' => $request->user()->id]);

            return [$msg, true];
        }, 3);

        return response()->json(['data' => $this->messageData($data[0], (int) $member->joined_after_message_id)], $data[1] ? 201 : 200);
    }

    public function receipt(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        $this->chat($company, $id, $request->user());
        $input = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:100'],
            'messages.*' => ['required', 'integer', 'distinct'],
            'kind' => ['required', Rule::in(['delivered', 'read'])],
        ]);
        $q = ToirChatReceipt::where('company_id', $company->id)
            ->where('chat_id', $id)->where('user_id', $request->user()->id)
            ->whereIn('message_id', $input['messages']);
        $changes = DB::transaction(function () use ($q, $input, $company, $id, $request) {
            $count = $input['kind'] === 'read'
                ? $q->whereNull('read_at')->update(['read_at' => now(), 'delivered_at' => now()])
                : $q->whereNull('delivered_at')->update(['delivered_at' => now()]);
            if ($count) {
                $chat = ToirChat::where('company_id', $company->id)->findOrFail($id);
                ToirChatDeliveryService::changed($chat, $input['kind'], null, [
                    'user_id' => $request->user()->id, 'messages' => $input['messages'],
                ]);
            }

            return $count;
        }, 3);
        $unread = ToirChatReceipt::where('company_id', $company->id)->where('chat_id', $id)
            ->where('user_id', $request->user()->id)->whereNull('read_at')->count();

        return response()->json(['changed' => $changes, 'unread_count' => $unread]);
    }

    public function modify(Request $request, int $id, int $messageId): JsonResponse
    {
        $company = $this->company($request);
        $this->chat($company, $id, $request->user());
        $msg = ToirChatMessage::where('company_id', $company->id)->where('chat_id', $id)
            ->findOrFail($messageId);
        abort_unless($msg->user_id === $request->user()->id, 403, 'Редактировать можно только свои сообщения.');
        abort_if($msg->deleted_at !== null, 409, 'Сообщение удалено.');
        abort_if($msg->created_at->lt(now()->subMinutes(15)), 409, 'Время редактирования истекло.');
        $v = $request->validate(['body' => ['required', 'string', 'max:2048']]);
        $body = trim($v['body']);
        abort_if(! $body, 422, 'Нельзя сохранить пустое сообщение.');
        DB::transaction(function () use ($msg, $body, $company) {
            $msg->body = $body;
            $msg->edited_at = now();
            $msg->save();
            ToirChatDeliveryService::changed(ToirChat::where('company_id', $company->id)->findOrFail($msg->chat_id), 'edited', $msg);
        }, 3);

        return response()->json(['data' => $this->messageData($msg)]);
    }

    public function destroy(Request $request, int $id, int $messageId): JsonResponse
    {
        $company = $this->company($request);
        $this->chat($company, $id, $request->user());
        $msg = ToirChatMessage::where('company_id', $company->id)
            ->where('chat_id',$id)->findOrFail($messageId);
        abort_unless($msg->user_id === $request->user()->id,403,'Удалять можно только свои сообщения.');
        abort_if($msg->created_at->lt(now()->subMinutes(15)),409,'Время удаления истекло.');
        if (! $msg->deleted_at) {
            DB::transaction(function () use ($msg,$company) {
                $msg->deleted_at = now();
                $msg->body = '';
                $msg->save();
                ToirChatDeliveryService::changed(ToirChat::where('company_id',$company->id)->findOrFail($msg->chat_id),'deleted',$msg);
            },3);
        }

        return response()->json(['data' => $this->messageData($msg)]);
    }
}
