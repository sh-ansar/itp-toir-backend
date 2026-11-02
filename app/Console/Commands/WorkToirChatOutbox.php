<?php

namespace App\Console\Commands;

use App\Events\ToirChatSignal;
use App\Models\ToirChat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class WorkToirChatOutbox extends Command
{
    protected $signature = 'toir:chat-outbox {--once : Process at most one item and exit}';
    protected $description = 'Deliver committed TOIR chat updates with retries, independently of Portal';

    public function handle(): int
    {
        // SQLite DEFERRED transactions can fail while upgrading a read lock
        // to a write lock under concurrent session and chat writes.
        // Only this worker claims IMMEDIATE transactions.
        if(config('database.default')==='sqlite'){
            config([
                'database.connections.sqlite.transaction_mode'=>'IMMEDIATE',
                'database.connections.sqlite.busy_timeout'=>5000,
            ]);
        }
        $lock=fopen(storage_path('framework/toir-chat-outbox.lock'),'c');
        if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){
            $this->error('TOIR outbox worker already running');
            return self::FAILURE;
        }
        try{
            $busyErrors=0;
            do{
                try {
                $row=DB::transaction(function(){
                    $row=DB::table('toir_chat_outbox')
                        ->where('available_at','<=',now())
                        ->where(fn($q)=>$q->whereNull('locked_until')->orWhere('locked_until','<',now()))
                        ->orderBy('id')->lockForUpdate()->first();
                    if($row) DB::table('toir_chat_outbox')->where('id',$row->id)
                        ->update(['locked_until'=>now()->addSeconds(60)]);
                    return $row;
                });
                if($row){
                    try{
                        $chat=ToirChat::where('company_id',$row->company_id)->find($row->chat_id);
                        if($chat){
                            $recipients=$chat->members()->whereNull('left_at')
                                ->whereHas('user',fn($q)=>$q->where('is_active',true)
                                    ->whereHas('companies',fn($c)=>$c->where('companies.id',$chat->company_id)
                                        ->where('company_user.is_active',true)))
                                ->pluck('user_id')->all();
                            if($recipients){
                                broadcast(new ToirChatSignal(
                                    (int)$chat->company_id,(int)$chat->id,$row->kind,$recipients,
                                    $row->message_id?(int)$row->message_id:null,
                                    json_decode($row->metadata??'{}',true)??[]
                                ));
                            }
                        }
                        DB::table('toir_chat_outbox')->where('id',$row->id)->delete();
                    }catch(\Throwable $e){
                        $attempt=(int)$row->attempts+1;
                        DB::table('toir_chat_outbox')->where('id',$row->id)->update([
                            'attempts'=>$attempt,'locked_until'=>null,
                            'available_at'=>now()->addSeconds(min(300,2**min(8,$attempt))),
                        ]);
                        Log::warning('TOIR realtime delivery retry',[
                            'outbox_id'=>$row->id,'exception'=>get_class($e),
                        ]);
                        if($this->option('once'))$this->warn('Event retained; retry scheduled');
                    }
                }elseif(!$this->option('once'))usleep(250000);
                $busyErrors=0;
                }catch(\Illuminate\Database\QueryException $e){
                    if(!str_contains(strtolower($e->getMessage()),'database is locked')){
                        throw $e;
                    }
                    $busyErrors++;
                    if($busyErrors===1||$busyErrors%50===0){
                        Log::warning('TOIR chat outbox delayed: SQLite write lock',[
                            'consecutive_busy_attempts'=>$busyErrors,
                        ]);
                    }
                    if($this->option('once')){
                        $this->warn('SQLite busy; outbox event retained for retry');
                        break;
                    }
                    usleep(min(750000,100000+$busyErrors*25000));
                }
            }while(!$this->option('once'));
        }finally{flock($lock,LOCK_UN);fclose($lock);}
        return self::SUCCESS;
    }
}
