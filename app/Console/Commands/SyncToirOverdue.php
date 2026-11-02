<?php

namespace App\Console\Commands;

use App\Models\ToirWorkOrder;
use App\Models\ToirWorkOrderEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

final class SyncToirOverdue extends Command
{
    protected $signature = 'toir:sync-overdue {--dry-run : Do not change records}';
    protected $description = 'Mark pending maintenance work orders overdue and record the system event';

    public function handle(): int
    {
        $cutoff = now();
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;

        ToirWorkOrder::query()
            ->where('status', 'waiting')
            ->whereNotNull('planned_end')
            ->where('planned_end', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($cutoff, $dryRun, &$changed): void {
                foreach ($orders as $row) {
                    if ($dryRun) {
                        $changed++;
                        continue;
                    }
                    DB::transaction(function () use ($row, $cutoff, &$changed): void {
                        $order = ToirWorkOrder::whereKey($row->id)->lockForUpdate()->first();
                        if (!$order || $order->status !== 'waiting' ||
                            !$order->planned_end || Carbon::parse((string) $order->planned_end)->greaterThanOrEqualTo($cutoff)) {
                            return;
                        }

                        $order->status = 'overdue';
                        $order->save();

                        $event = new ToirWorkOrderEvent();
                        $event->company_id = $order->company_id;
                        $event->work_order_id = $order->id;
                        $event->user_id = null;
                        $event->action = 'overdue';
                        $event->message = 'Срок выполнения истёк. Статус изменён автоматически.';
                        $event->changes = ['from'=>'waiting','to'=>'overdue'];
                        $event->save();
                        $changed++;
                    });
                }
            });

        $this->info(($dryRun ? 'DRY_RUN ' : '').'TOIR_OVERDUE_CHANGED='.$changed);

        return self::SUCCESS;
    }
}
