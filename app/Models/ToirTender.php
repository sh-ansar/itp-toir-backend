<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ToirTender extends ToirRecord
{
    protected $table = 'toir_tenders';
}
