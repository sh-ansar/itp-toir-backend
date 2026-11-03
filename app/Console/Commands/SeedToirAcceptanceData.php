<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Throwable;

final class SeedToirAcceptanceData extends Command
{
    protected $signature = 'toir:seed-acceptance {--company-id= : Target existing company id} {--apply : Persist the QA dataset}';
    protected $description = 'Idempotent labelled acceptance dataset; does not create users or change existing records';

    public function handle(): int
    {
        $id=filter_var($this->option('company-id'),FILTER_VALIDATE_INT);
        if(!$id || $id<1){$this->error('Specify --company-id=N explicitly.');return self::FAILURE;}
        $company=Company::query()->whereKey($id)->where('is_active',true)->first();
        if(!$company){$this->error('Active company not found.');return self::FAILURE;}
        $tables=['toir_assets','toir_employees','toir_crews','toir_crew_members','toir_crew_shifts',
            'toir_projects','toir_maintenance_plans','toir_maintenance_steps','toir_work_orders',
            'toir_work_order_events','toir_work_order_steps','toir_stock_items','toir_stock_movements',
            'toir_work_order_materials','toir_asset_readings','toir_supplies','toir_tenders'];
        foreach($tables as $table){if(!Schema::hasTable($table)){ $this->error('Missing table: '.$table);return self::FAILURE;}}
        if(!$this->option('apply')){
            $this->info('DRY RUN. QA dataset target: '.$company->name.' (id '.$id.'). Run with --apply to populate existing company.');
            return self::SUCCESS;
        }
        $created=[];
        $count=0;
        try{
            DB::transaction(function()use($id,&$created,&$count){
                $now=now();$day=Carbon::today();
                $add=function(string $table,array $key,array $data=[])use($id,$now,&$created,&$count):int {
                    $query=DB::table($table)->where('company_id',$id);
                    foreach($key as $k=>$v)$query->where($k,$v);
                    $existing=$query->first();
                    if($existing)return (int)$existing->id;
                    $fields=array_merge(['company_id'=>$id],$key,$data);
                    if(Schema::hasColumn($table,'updated_at'))$fields['updated_at']=$now;
                    if(Schema::hasColumn($table,'created_at'))$fields['created_at']=$now;
                    $new=(int)DB::table($table)->insertGetId($fields);
                    $created[$table]=($created[$table]??0)+1;$count++;
                    return $new;
                };
                $areas=[];
                foreach([
                    ['QA26-P01','[ТЕСТ] Цех №1','Производственный комплекс'],
                    ['QA26-P02','[ТЕСТ] Цех №2','Производственный комплекс'],
                    ['QA26-P03','[ТЕСТ] Компрессорная станция','Основная промплощадка'],
                    ['QA26-P04','[ТЕСТ] Электрощитовая','Производственный комплекс'],
                ] as [$code,$name,$location]){
                    $areas[$code]=$add('toir_assets',['code'=>$code],[
                      'name'=>$name,'category'=>'premises','node_type'=>'object','location'=>$location,
                      'status'=>'active','criticality'=>3,'purchase_value'=>22000000,'description'=>'Тестовый объект для приёмки ITP ТОиР.']);
                }
                $assets=[];
                foreach([
                    ['QA26-E101','[ТЕСТ] Шкаф взрывозащищённый ТЕХ 619 120','QA26-P01',82,1450000],
                    ['QA26-E105','[ТЕСТ] Теплообменник пластинчатый ТП-450','QA26-P01',62,1910000],
                    ['QA26-E109','[ТЕСТ] Гидравлическая станция ГС-90','QA26-P01',43,2370000],
                    ['QA26-E102','[ТЕСТ] Насос циркуляционный NCP-180','QA26-P02',88,1565000],
                    ['QA26-E106','[ТЕСТ] Редуктор привода РМ-500','QA26-P02',74,2025000],
                    ['QA26-E110','[ТЕСТ] Насос дозировочный НД-50','QA26-P02',93,2485000],
                    ['QA26-E103','[ТЕСТ] Компрессор винтовой КВ-200','QA26-P03',51,1680000],
                    ['QA26-E107','[ТЕСТ] Вентиляционная установка ВУ-1200','QA26-P03',70,2140000],
                    ['QA26-E111','[ТЕСТ] Конвейер ленточный ЛК-03','QA26-P03',58,2600000],
                    ['QA26-E104','[ТЕСТ] Трансформатор ТМГ-630','QA26-P04',91,4950000],
                    ['QA26-E108','[ТЕСТ] Автоматический выключатель АВ-400','QA26-P04',83,360000],
                    ['QA26-E112','[ТЕСТ] Щит управления ЩУ-02','QA26-P04',86,740000],
                ] as [$code,$name,$parent,$condition,$value]){
                    $assets[$code]=$add('toir_assets',['code'=>$code],[
                        'name'=>$name,'parent_id'=>$areas[$parent],'location'=>$parent,'category'=>'equipment',
                        'node_type'=>'aggregate','status'=>$condition<50?'maintenance':'active',
                        'criticality'=>$condition<60?5:3,'condition_score'=>$condition,
                        'condition_recorded_at'=>$now,'operating_hours'=>random_int(2200,8000),
                        'purchase_value'=>$value,'commissioned_at'=>$day->copy()->subYears(2)->toDateString(),
                        'manufacturer'=>'Тестовый производитель','model'=>$code,
                        'description'=>'[ТЕСТ] Реестр оборудования. Не использовать для реальных работ.']);
                }
                $employees=[];
                foreach([
                    ['QA26-EMP01','[ТЕСТ] Александр Петров','Мастер участка','Цех №1'],
                    ['QA26-EMP02','[ТЕСТ] Дмитрий Ковалёв','Механик','Цех №1'],
                    ['QA26-EMP03','[ТЕСТ] Игорь Сергеев','Электромонтёр','Электрощитовая'],
                    ['QA26-EMP04','[ТЕСТ] Сергей Нурланов','Механик','Компрессорная'],
                    ['QA26-EMP05','[ТЕСТ] Мария Омарова','Инженер ТОиР','Служба ТОиР']
                ] as [$email,$name,$position,$department]){
                    $employees[$email]=$add('toir_employees',['email'=>strtolower($email).'@example.invalid'],[
                        'name'=>$name,'position'=>$position,'department'=>$department,'status'=>'active']);
                }
                $crew1=$add('toir_crews',['code'=>'QA26-MECH'],[
                    'name'=>'[ТЕСТ] Механическая бригада','status'=>'active','lead_employee_id'=>$employees['QA26-EMP01']]);
                $crew2=$add('toir_crews',['code'=>'QA26-ELEC'],[
                    'name'=>'[ТЕСТ] Электротехническая бригада','status'=>'active','lead_employee_id'=>$employees['QA26-EMP03']]);
                foreach([[$crew1,'QA26-EMP01'],[$crew1,'QA26-EMP02'],[$crew1,'QA26-EMP04'],[$crew2,'QA26-EMP03']] as [$crew,$emp]){
                    $add('toir_crew_members',['crew_id'=>$crew,'employee_id'=>$employees[$emp]],['role'=>'member']);
                }
                foreach([$crew1,$crew2] as $crew){
                    for($x=0;$x<5;$x++){
                        $add('toir_crew_shifts',['crew_id'=>$crew,'work_date'=>$day->copy()->addDays($x)->toDateString(),
                            'starts_at'=>'08:00:00'],['ends_at'=>'17:00:00','status'=>'confirmed','note'=>'[ТЕСТ] Плановая смена']);
                    }
                }
                $project=$add('toir_projects',['code'=>'QA26-PRJ01'],[
                    'name'=>'[ТЕСТ] Модернизация насосного оборудования','manager_name'=>'Мария Омарова',
                    'status'=>'active','start_date'=>$day->copy()->subDays(12)->toDateString(),
                    'end_date'=>$day->copy()->addDays(70)->toDateString()]);
                $plans=[];
                foreach([
                    ['[ТЕСТ] ТО насоса циркуляционного', 'QA26-E102',30,5,$crew1],
                    ['[ТЕСТ] Проверка гидравлической станции','QA26-E109',14,-3,$crew1],
                    ['[ТЕСТ] Сервис компрессора КВ-200','QA26-E103',45,7,$crew1],
                    ['[ТЕСТ] Проверка электрического щита','QA26-E112',90,25,$crew2],
                    ['[ТЕСТ] Диагностика вентиляции','QA26-E107',30,10,$crew2],
                ] as [$title,$assetCode,$interval,$due,$crew]){
                    $plan=$add('toir_maintenance_plans',['title'=>$title],[
                      'asset_id'=>$assets[$assetCode],'status'=>'active','periodicity_days'=>$interval,
                      'start_date'=>$day->copy()->subDays(90)->toDateString(),
                      'next_due_date'=>$day->copy()->addDays($due)->toDateString(),
                      'crew_id'=>$crew,'priority'=>$due<0?1:3,'duration_minutes'=>120,
                      'description'=>'[ТЕСТ] Регламентное обслуживание','work_instructions'=>'Отключить питание, проверить узлы, зафиксировать результат.',
                      'safety_instructions'=>'Применять СИЗ, наряд-допуск по регламенту.']);
                    $plans[]=$plan;
                    foreach(['Проверить безопасное отключение','Измерить рабочие параметры','Осмотреть соединения'] as $ix=>$step){
                        $add('toir_maintenance_steps',['maintenance_plan_id'=>$plan,'position'=>$ix+1],[
                          'title'=>$step,'kind'=>$ix===1?'measurement':'inspection',
                          'unit'=>$ix===1?'мм/с':null,'is_required'=>1]);
                    }
                }
                $orders=[];
                foreach([
                    ['QA26-WO-001','[ТЕСТ] Замена подшипников гидростанции','QA26-E109','closed',1,-9,'QA26-EMP02'],
                    ['QA26-WO-002','[ТЕСТ] Течь насоса NCP-180','QA26-E102','in_progress',2,1,'QA26-EMP02'],
                    ['QA26-WO-003','[ТЕСТ] Вибрация компрессора','QA26-E103','waiting',1,3,null],
                    ['QA26-WO-004','[ТЕСТ] Осмотр теплообменника','QA26-E105','awaiting_acceptance',3,-1,'QA26-EMP04'],
                    ['QA26-WO-005','[ТЕСТ] Проверка щита управления','QA26-E112','waiting',3,6,'QA26-EMP03'],
                    ['QA26-WO-006','[ТЕСТ] Просроченная проверка вентиляции','QA26-E107','overdue',2,-4,null],
                ] as [$number,$title,$asset,$status,$priority,$shift,$employee]){
                    $started=in_array($status,['closed','in_progress','awaiting_acceptance'],true);
                    $o=$add('toir_work_orders',['number'=>$number],[
                        'asset_id'=>$assets[$asset],'project_id'=>$project,
                        'employee_id'=>$employee?$employees[$employee]:null,
                        'title'=>$title,'description'=>'[ТЕСТ] Учебный наряд для проверки бизнес-процесса.',
                        'type'=>'emergency','status'=>$status,'priority'=>$priority,
                        'planned_start'=>$day->copy()->addDays($shift)->setTime(8,0)->toDateTimeString(),
                        'planned_end'=>$day->copy()->addDays($shift)->setTime(16,0)->toDateTimeString(),
                        'actual_start'=>$started?$day->copy()->addDays($shift)->setTime(9,0)->toDateTimeString():null,
                        'actual_end'=>in_array($status,['closed','awaiting_acceptance'],true)?
                            $day->copy()->addDays($shift)->setTime(14,0)->toDateTimeString():null,
                        'actual_labor_minutes'=>$status==='closed'?170:($status==='awaiting_acceptance'?90:null),
                        'estimated_cost'=>250000,
                        'crew_id'=>in_array($asset,['QA26-E112','QA26-E107'],true)?$crew2:$crew1]);
                    $orders[$number]=$o;
                    $add('toir_work_order_events',['work_order_id'=>$o,'action'=>'qa_seed'],[
                      'message'=>'Тестовый наряд. Создан автоматизированным наполнением.',
                      'changes'=>json_encode(['dataset'=>'QA26','status'=>$status],JSON_UNESCAPED_UNICODE)]);
                }
                $planned=$add('toir_work_orders',['number'=>'QA26-WO-007'],[
                    'asset_id'=>$assets['QA26-E102'],'maintenance_plan_id'=>$plans[0],
                    'title'=>'[ТЕСТ] Регламентный осмотр насоса NCP-180',
                    'description'=>'[ТЕСТ] Регламент с обязательными шагами проверки',
                    'type'=>'planned','status'=>'waiting','priority'=>3,
                    'employee_id'=>$employees['QA26-EMP01'],'crew_id'=>$crew1,
                    'scheduled_for'=>$day->copy()->addDays(5)->toDateString(),
                    'planned_start'=>$day->copy()->addDays(5)->setTime(8,0)->toDateTimeString(),
                    'planned_end'=>$day->copy()->addDays(5)->setTime(10,0)->toDateTimeString(),
                    'duration_minutes'=>120]);
                $checkSteps=DB::table('toir_maintenance_steps')->where('company_id',$id)
                    ->where('maintenance_plan_id',$plans[0])->orderBy('position')->get();
                foreach($checkSteps as $step){
                    $add('toir_work_order_steps',['work_order_id'=>$planned,'position'=>$step->position],[
                        'maintenance_step_id'=>$step->id,'title'=>$step->title,
                        'kind'=>$step->kind,'instructions'=>$step->instructions,
                        'is_required'=>$step->is_required,'unit'=>$step->unit,'status'=>'pending']);
                }
                foreach([
                    ['QA26-BEARING','[ТЕСТ] Подшипник 6308','шт.',15,3,19500],
                    ['QA26-SEAL','[ТЕСТ] Уплотнение насоса','шт.',12,1,22500],
                    ['QA26-OIL','[ТЕСТ] Масло гидравлическое','л',80,0,4900],
                    ['QA26-FILTER','[ТЕСТ] Фильтр компрессорный','шт.',10,0,13700],
                ] as [$sku,$name,$unit,$received,$used,$unitCost]){
                    $item=$add('toir_stock_items',['sku'=>$sku],[
                      'name'=>$name,'unit'=>$unit,'quantity_on_hand'=>$received-$used,
                      'quantity_reserved'=>0,'unit_cost'=>$unitCost,'minimum_quantity'=>2]);
                    $add('toir_stock_movements',['stock_item_id'=>$item,'action'=>'receipt','note'=>'[ТЕСТ] Первичный приход QA26'],[
                      'quantity'=>$received,'delta_on_hand'=>$received,'delta_reserved'=>0,'unit_cost'=>$unitCost]);
                    if($used){
                        $order=$orders['QA26-WO-001'];
                        $add('toir_work_order_materials',['work_order_id'=>$order,'stock_item_id'=>$item],[
                          'quantity_used'=>$used,'actual_cost'=>$used*$unitCost,'quantity_reserved'=>0]);
                        $add('toir_stock_movements',['stock_item_id'=>$item,'action'=>'consume','work_order_id'=>$order],[
                          'quantity'=>$used,'delta_on_hand'=>-$used,'delta_reserved'=>0,'unit_cost'=>$unitCost,
                          'note'=>'[ТЕСТ] Списание на завершённый наряд']);
                    }
                }
                foreach([
                    ['QA26-E109','condition_score',43],['QA26-E109','operating_hours',7850],
                    ['QA26-E103','condition_score',51],['QA26-E102','operating_hours',4100],
                    ['QA26-E107','condition_score',70]
                ] as [$code,$meter,$value]){
                    $add('toir_asset_readings',['asset_id'=>$assets[$code],'meter'=>$meter,'source'=>'qa_seed'],[
                      'value'=>$value,'recorded_at'=>$now,'note'=>'[ТЕСТ] Первичное показание']);
                }
                $add('toir_supplies',['name'=>'[ТЕСТ] Закупка ремкомплекта насоса'],[
                  'category'=>'materials','asset_id'=>$assets['QA26-E102'],'quantity'=>5,'unit'=>'шт.',
                  'unit_price'=>28000,'status'=>'requested','requested_at'=>$day->toDateString()]);
                $add('toir_tenders',['number'=>'QA26-TN-001'],[
                    'title'=>'[ТЕСТ] Закупка расходных материалов ТОиР','status'=>'draft',
                    'budget'=>540000,'deadline_at'=>$day->copy()->addDays(16)->toDateString(),
                    'description'=>'Тестовый тендер. Не является реальной закупкой.']);
                // The channel is visible to an existing owner, without creating or impersonating users.
                $owner=DB::table('company_user')->join('users','users.id','=','company_user.user_id')
                    ->where('company_user.company_id',$id)->where('company_user.role','owner')
                    ->where('company_user.is_active',1)->where('users.is_active',1)
                    ->value('users.id');
                if($owner){
                    $chat=$add('toir_chats',['kind'=>'channel','title'=>'[ТЕСТ] Канал ТОиР QA26'],
                        ['created_by'=>null]);
                    $add('toir_chat_members',['chat_id'=>$chat,'user_id'=>(int)$owner],
                        ['is_admin'=>true,'joined_after_message_id'=>0,'joined_at'=>$now]);
                    $add('toir_chat_messages',['chat_id'=>$chat,'client_token'=>'QA26-WELCOME'],[
                        'user_id'=>null,'body'=>'[ТЕСТ] Канал для проверки чата ТОиР. Не содержит рабочих распоряжений.']);
                }
            },3);
        }catch(Throwable $exception){
            $this->error('QA seed rolled back: '.$exception->getMessage());
            return self::FAILURE;
        }
        $this->info('TOIR_QA26_SEED_OK company_id='.$id.' inserted='.$count.' details='.json_encode($created));
        return self::SUCCESS;
    }
}
