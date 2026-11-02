<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToirChatMember extends Model
{
    protected $table='toir_chat_members';
    protected $guarded=['id','company_id','chat_id'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function chat(): BelongsTo { return $this->belongsTo(ToirChat::class,'chat_id'); }
}
