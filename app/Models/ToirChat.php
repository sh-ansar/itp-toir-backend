<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ToirChat extends Model
{
    protected $table = 'toir_chats';

    protected $guarded = ['id', 'company_id'];

    public function members(): HasMany
    {
        return $this->hasMany(ToirChatMember::class, 'chat_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ToirChatMessage::class, 'chat_id');
    }
}
