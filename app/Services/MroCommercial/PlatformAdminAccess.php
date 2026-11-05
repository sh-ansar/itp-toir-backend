<?php
namespace App\Services\MroCommercial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
final class PlatformAdminAccess {
 public static function allowed(?User $user,string $permission='platform.access'):bool {
  if(!$user || !$user->email_verified_at || !$user->is_active || !$user->activeCompanies()->exists())return false;
  $q=DB::table('mro_admin_grants')->where('user_id',$user->id);
  if($permission==='platform.access')return $q->exists();
  return $q->whereIn('permission',['platform.manage',$permission])->exists();
 }
 public static function require(?User $user,string $permission):void {abort_unless(self::allowed($user,$permission),403);}
}
