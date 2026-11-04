<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ToirChatReceipt extends Model
{
    public $timestamps = false;

    protected $table = 'toir_chat_receipts';

    protected $guarded = ['id', 'company_id', 'chat_id', 'message_id', 'user_id'];
}
