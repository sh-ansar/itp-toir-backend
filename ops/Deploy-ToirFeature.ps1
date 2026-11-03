#requires -Version 5.1
<#
Deploy ITP TOIR only. Read-only unless -Apply is supplied.
The existing HRM .env is used as a read-only source for SMTP settings.
No passwords are printed. Other company applications and Git main are untouched.
#>
[CmdletBinding()]
param(
  [string]$BackendSource='C:\deploy\toir-workspace\backend',
  [string]$FrontendSource='C:\deploy\toir-workspace\frontend',
  [string]$BackendTarget='C:\deploy\toir\backend\current',
  [string]$FrontendTarget='C:\deploy\toir\site\current',
  [string]$PhpPath='C:\deploy\hrm\runtime\php-8.4.26-nts\php.exe',
  [string]$HrmEnvPath,
  [string]$BackupRoot='C:\deploy\toir\backups',
  [string]$TestRecipient,
  [string]$PublicUrl='https://platform.itpmining.kz/mro',
  [switch]$Apply
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$branch='feature/toir-foundation'
$service='ITP-TOIR-FastCGI'
$tasks=@('ITP-TOIR-ChatWorker','ITP-TOIR-Overdue','ITP-TOIR-Reverb')
$directories=@('app','routes','config','database\migrations')
$db=Join-Path $BackendTarget 'database\database.sqlite'
$envFile=Join-Path $BackendTarget '.env'
$smtpScript=Join-Path $BackendSource 'ops\Configure-ToirSmtpFromHrm.ps1'
foreach($item in @($BackendSource,$FrontendSource,$BackendTarget,$FrontendTarget,$PhpPath,$db,$envFile,$smtpScript)){
  if(!(Test-Path -LiteralPath $item)){throw "Missing TOIR prerequisite: $item"}
}
if([string]::IsNullOrWhiteSpace($HrmEnvPath) -or !(Test-Path -LiteralPath $HrmEnvPath -PathType Leaf)){
  throw 'A valid HrmEnvPath must be specified.'
}
if($PublicUrl -notmatch '^https://[^/]+/mro/?$'){throw 'Expected an HTTPS TOIR /mro URL.'}
if($BackupRoot -match '[\\/](public|public_html)([\\/]|$)'){throw 'Backups must not be web accessible.'}
if((Get-Item -LiteralPath $db).Length -lt 4096){throw 'TOIR database size is suspiciously small.'}

function Run([string]$program,[string[]]$arguments){
  & $program @arguments
  if($LASTEXITCODE -ne 0){throw "Process returned $LASTEXITCODE : $program"}
}
function SafeCopy([string]$source,[string]$destination){
  New-Item -ItemType Directory -Path $destination -Force|Out-Null
  & robocopy.exe $source $destination /E /R:2 /W:1 /NFL /NDL /NJH /NJS /NP | Out-Null
  if($LASTEXITCODE -ge 8){throw "Robocopy failed: $source"}
}
function SafeMirror([string]$source,[string]$destination){
  New-Item -ItemType Directory -Path $destination -Force|Out-Null
  & robocopy.exe $source $destination /MIR /R:2 /W:1 /NFL /NDL /NJH /NJS /NP | Out-Null
  if($LASTEXITCODE -ge 8){throw "Robocopy failed: $source"}
}
function CheckBranch([string]$directory){
  $current=(& git -C $directory branch --show-current|Out-String).Trim()
  if($LASTEXITCODE -ne 0 -or $current -ne $branch){throw "Unexpected branch: $directory"}
  $dirty=(& git -C $directory status --porcelain|Out-String).Trim()
  if($LASTEXITCODE -ne 0 -or $dirty){throw "Uncommitted changes: $directory"}
}
foreach($repo in @($BackendSource,$FrontendSource)){CheckBranch $repo}
Write-Output 'TOIR_DEPLOY_PREFLIGHT_OK'
if(!$Apply){
  Write-Output 'TOIR_DEPLOY_DRY_RUN: no code, database, service or mail configuration changed.'
  return
}
if([string]::IsNullOrWhiteSpace($TestRecipient)){throw 'Set TestRecipient to a real address before applying SMTP.'}

# Advance only feature branches without discarding uncommitted work.
foreach($repo in @($BackendSource,$FrontendSource)){
  Run 'git' @('-C',$repo,'fetch','origin',$branch)
  Run 'git' @('-C',$repo,'merge','--ff-only','FETCH_HEAD')
  CheckBranch $repo
}
Run $PhpPath @((Join-Path $BackendSource 'artisan'),'test','--compact')
Push-Location $FrontendSource
try{
  Run 'npm.cmd' @('ci','--no-audit','--no-fund')
  $previousBase=$env:VITE_BASE_PATH
  try{
    $env:VITE_BASE_PATH='/mro/'
    Run 'npm.cmd' @('run','build')
  }finally{$env:VITE_BASE_PATH=$previousBase}
}finally{Pop-Location}
$dist=Join-Path $FrontendSource 'dist'
foreach($rel in @('index.html','fonts.css')){
  if(!(Test-Path -LiteralPath (Join-Path $dist $rel))){throw "Missing frontend build file: $rel"}
}
if(!([IO.File]::ReadAllText((Join-Path $dist 'index.html'))).Contains('/mro/')){
  throw 'Frontend was not built for /mro/.'
}

$backup=Join-Path $BackupRoot ('toir-before-feature-'+(Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $backup -Force|Out-Null
$stoppedTasks=New-Object 'System.Collections.Generic.List[string]'
$serviceStopped=$false
$backupOk=$false
$modified=$false
try{
  if((Get-Service -Name $service -ErrorAction Stop).Status -ne 'Running'){
    throw 'TOIR FastCGI must be running before a managed deployment.'
  }
  foreach($task in $tasks){
    $found=Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue
    if($found -and $found.State -eq 'Running'){
      Stop-ScheduledTask -TaskName $task -ErrorAction Stop
      $stoppedTasks.Add($task)
    }
  }
  Stop-Service -Name $service -ErrorAction Stop
  (Get-Service -Name $service).WaitForStatus('Stopped',[TimeSpan]::FromSeconds(30))
  $serviceStopped=$true
  foreach($suffix in @('','-wal','-shm')){
    if(Test-Path -LiteralPath ($db+$suffix)){
      Copy-Item -LiteralPath ($db+$suffix) -Destination (Join-Path $backup ('database.sqlite'+$suffix)) -Force
    }
  }
  Copy-Item -LiteralPath $envFile -Destination (Join-Path $backup 'toir-env.private') -Force
  foreach($rel in $directories){
    SafeMirror (Join-Path $BackendTarget $rel) (Join-Path $backup ('backend\'+$rel))
  }
  SafeMirror $FrontendTarget (Join-Path $backup 'site')
  if(!(Test-Path -LiteralPath (Join-Path $backup 'database.sqlite')) -or
     !(Test-Path -LiteralPath (Join-Path $backup 'site\index.html'))){
    throw 'Database/frontend backup verification failed.'
  }
  $backupOk=$true
  Write-Output 'TOIR_BACKUP_VERIFIED'
  $modified=$true
  foreach($rel in $directories){
    SafeCopy (Join-Path $BackendSource $rel) (Join-Path $BackendTarget $rel)
  }
  Run $PhpPath @((Join-Path $BackendTarget 'artisan'),'config:clear')
  Run $PhpPath @((Join-Path $BackendTarget 'artisan'),'route:clear')
  Run $PhpPath @((Join-Path $BackendTarget 'artisan'),'migrate','--force','--no-interaction')

  $arguments=@('-NoProfile','-ExecutionPolicy','Bypass','-File',$smtpScript,
     '-HrmEnvPath',$HrmEnvPath,'-ToirEnvPath',$envFile,
     '-ToirAppPath',$BackendTarget,'-PhpPath',$PhpPath,
     '-BackupDirectory',$BackupRoot,'-TestRecipient',$TestRecipient,'-FrontendUrl',$PublicUrl,'-Apply')
  Run 'powershell.exe' $arguments
  SafeMirror $dist $FrontendTarget
  Start-Service -Name $service -ErrorAction Stop
  (Get-Service -Name $service).WaitForStatus('Running',[TimeSpan]::FromSeconds(30))
  $serviceStopped=$false
  $health=Invoke-WebRequest -UseBasicParsing -Uri ($PublicUrl.TrimEnd('/')+'/api/v1/health') -TimeoutSec 20
  if([int]$health.StatusCode -ne 200){throw 'Published API health is not 200.'}
  $index=Invoke-WebRequest -UseBasicParsing -Uri ($PublicUrl.TrimEnd('/')+'/') -TimeoutSec 20
  if([int]$index.StatusCode -ne 200){throw 'Published frontend is not 200.'}
  foreach($task in $stoppedTasks){Start-ScheduledTask -TaskName $task -ErrorAction Stop}
  $stoppedTasks.Clear()
  Write-Output 'TOIR_FEATURE_DEPLOY_AND_SMTP_SUCCESS'
}catch{
  $why=$_.Exception.Message
  Write-Output ('TOIR_DEPLOY_FAILED: '+$why)
  if($backupOk -and $modified){
    try{
      if((Get-Service -Name $service).Status -eq 'Running'){
        Stop-Service -Name $service -ErrorAction Stop
        (Get-Service -Name $service).WaitForStatus('Stopped',[TimeSpan]::FromSeconds(30))
        $serviceStopped=$true
      }
      foreach($rel in $directories){
        SafeMirror (Join-Path $backup ('backend\'+$rel)) (Join-Path $BackendTarget $rel)
      }
      SafeMirror (Join-Path $backup 'site') $FrontendTarget
      Copy-Item -LiteralPath (Join-Path $backup 'toir-env.private') -Destination $envFile -Force
      foreach($suffix in @('','-wal','-shm')){
        $snapshot=Join-Path $backup ('database.sqlite'+$suffix)
        if(Test-Path -LiteralPath $snapshot){Copy-Item -LiteralPath $snapshot -Destination ($db+$suffix) -Force}
        elseif(Test-Path -LiteralPath ($db+$suffix)){Remove-Item -LiteralPath ($db+$suffix) -Force}
      }
      Run $PhpPath @((Join-Path $BackendTarget 'artisan'),'config:clear')
      Run $PhpPath @((Join-Path $BackendTarget 'artisan'),'route:clear')
      Write-Output 'TOIR_ROLLBACK_SUCCESS'
    }catch{Write-Output ('TOIR_ROLLBACK_NEEDS_MANUAL_REVIEW: '+$_.Exception.Message)}
  }
  throw
}finally{
  if($serviceStopped -or (Get-Service -Name $service -ErrorAction SilentlyContinue).Status -ne 'Running'){
    try{Start-Service -Name $service -ErrorAction Stop}
    catch{Write-Output ('TOIR_SERVICE_RESTART_FAILED: '+$_.Exception.Message)}
  }
  foreach($task in $stoppedTasks){
    try{Start-ScheduledTask -TaskName $task -ErrorAction Stop}
    catch{Write-Output ('TOIR_TASK_RESTART_FAILED: '+$task)}
  }
}
