#requires -Version 5.1
<#
  Copy only SMTP transport settings from an existing HRM .env into TOIR .env.
  Read-only unless -Apply is given. Never prints passwords or MAIL_URL.
  Production TOIR must already contain the "toir:mail-check" command.
#>
[CmdletBinding()]
param(
  [Parameter(Mandatory=$true)][string]$HrmEnvPath,
  [Parameter(Mandatory=$true)][string]$ToirEnvPath,
  [Parameter(Mandatory=$true)][string]$ToirAppPath,
  [Parameter(Mandatory=$true)][string]$PhpPath,
  [Parameter(Mandatory=$true)][string]$BackupDirectory,
  [string]$TestRecipient = '',
  [string]$FrontendUrl = 'https://platform.itpmining.kz/mro',
  [switch]$Apply
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
foreach($p in @($HrmEnvPath,$ToirEnvPath,$PhpPath)){
  if(!(Test-Path -LiteralPath $p -PathType Leaf)){throw "Missing file: $p"}
}
if(!(Test-Path -LiteralPath (Join-Path $ToirAppPath 'artisan'))){throw 'TOIR artisan not found'}
$hrmPath=(Resolve-Path -LiteralPath $HrmEnvPath).Path
$toirPath=(Resolve-Path -LiteralPath $ToirEnvPath).Path
if($hrmPath -eq $toirPath){throw 'HRM and TOIR must have different .env files'}
if($BackupDirectory -match '[\\/](public|public_html)([\\/]|$)'){throw 'Backups must be outside the public web root'}
if($FrontendUrl -notmatch '^https://[^/]+/mro/?$'){throw 'Invalid TOIR HTTPS frontend URL'}
function ReadEnv([string]$file){
  $raw=[IO.File]::ReadAllText($file,[Text.Encoding]::UTF8)
  $values=@{}
  foreach($line in ($raw -split '\r?\n')){
    if($line -match '^\s*([A-Za-z_][A-Za-z_0-9]*)\s*=(.*)$'){
      $values[$matches[1]]=$matches[2].Trim()
    }
  }
  return @{ Raw=$raw; Values=$values }
}
function Plain([object]$value){
  if($null -eq $value){return ''}
  $s=[string]$value
  if($s.Length -ge 2 -and (($s.StartsWith('"') -and $s.EndsWith('"')) -or
     ($s.StartsWith("'") -and $s.EndsWith("'")))){return $s.Substring(1,$s.Length-2)}
  return $s
}
$hrm=ReadEnv $hrmPath
$toir=ReadEnv $toirPath
if((Plain $hrm.Values['MAIL_MAILER']) -ne 'smtp'){throw 'HRM mailer is not explicitly SMTP; refusing to copy'}
$hostName=Plain $hrm.Values['MAIL_HOST']
$mailUrl=Plain $hrm.Values['MAIL_URL']
if($hostName -eq '' -and $mailUrl -eq ''){throw 'HRM SMTP host or URL not configured'}
if($hostName -ne ''){
  $port=0
  if(![int]::TryParse((Plain $hrm.Values['MAIL_PORT']),[ref]$port) -or
     $port -lt 1 -or $port -gt 65535){throw 'HRM SMTP port is invalid'}
}
$from=Plain $hrm.Values['MAIL_FROM_ADDRESS']
try{$parsed=[System.Net.Mail.MailAddress]::new($from)}catch{throw 'Invalid HRM sender address'}
if($parsed.Address -ne $from -or $from -match '@example\.(com|org|test|invalid)$'){
  throw 'Invalid or placeholder HRM sender'
}
if((Plain $hrm.Values['MAIL_USERNAME']) -ne '' -and
   (Plain $hrm.Values['MAIL_PASSWORD']) -eq '' -and $mailUrl -eq ''){
  throw 'SMTP username is configured but password is missing'
}
$keys=@('MAIL_MAILER','MAIL_URL','MAIL_HOST','MAIL_PORT','MAIL_USERNAME',
  'MAIL_PASSWORD','MAIL_SCHEME','MAIL_ENCRYPTION','MAIL_EHLO_DOMAIN',
  'MAIL_FROM_ADDRESS','MAIL_FROM_NAME','MAIL_TIMEOUT','TOIR_FRONTEND_URL')
$replacements=@{}
foreach($key in $keys){
  if($key -eq 'MAIL_FROM_NAME'){continue}
  if($hrm.Values.ContainsKey($key)){
    $val=[string]$hrm.Values[$key]
    if($val.Contains('$'+'{')){throw "HRM key $key references another environment variable"}
    $replacements[$key]=$val
  }
}
$replacements['MAIL_FROM_NAME']='"ITP ТОиР"'
$replacements['TOIR_FRONTEND_URL']=$FrontendUrl.TrimEnd('/')
$seen=@{}
$lines=New-Object 'System.Collections.Generic.List[string]'
foreach($line in ($toir.Raw -split '\r?\n')){
  if($line -match '^\s*([A-Za-z_][A-Za-z_0-9]*)\s*=(.*)$'){
    $key=$matches[1]
    if($keys -contains $key){
      if(!$seen.ContainsKey($key) -and $replacements.ContainsKey($key)){
        $lines.Add($key+'='+$replacements[$key]);$seen[$key]=$true
      }
      continue
    }
  }
  $lines.Add($line)
}
foreach($key in $keys){
  if($replacements.ContainsKey($key) -and !$seen.ContainsKey($key)){
    $lines.Add($key+'='+$replacements[$key])
  }
}
$crlf=[string][char]13+[string][char]10
$lf=[string][char]10
$newline=if($toir.Raw.Contains($crlf)){$crlf}else{$lf}
$updated=[string]::Join($newline,$lines.ToArray())
if(!$updated.EndsWith($newline)){$updated+=$newline}
Write-Output 'TOIR_SMTP_SOURCE_VALIDATED'
Write-Output ('TOIR_SMTP_KEYS_PREPARED count='+$replacements.Count)
if(!$Apply){Write-Output 'TOIR_SMTP_DRY_RUN: no files modified';return}
if([string]::IsNullOrWhiteSpace($TestRecipient)){
  throw 'A verified -TestRecipient is required with -Apply to test the actual SMTP transport.'
}
New-Item -ItemType Directory -Path $BackupDirectory -Force | Out-Null
$backup=Join-Path $BackupDirectory ('toir-env-before-smtp-'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'.private')
Copy-Item -LiteralPath $toirPath -Destination $backup -ErrorAction Stop
$hadCache=Test-Path -LiteralPath (Join-Path $ToirAppPath 'bootstrap\cache\config.php')
function RefreshConfig {
  & $PhpPath (Join-Path $ToirAppPath 'artisan') config:clear | Out-Null
  if($LASTEXITCODE -ne 0){throw 'TOIR config:clear failed'}
  if($hadCache){
    & $PhpPath (Join-Path $ToirAppPath 'artisan') config:cache | Out-Null
    if($LASTEXITCODE -ne 0){throw 'TOIR config:cache failed'}
  }
}
try{
  [IO.File]::WriteAllText($toirPath,$updated,(New-Object Text.UTF8Encoding($false)))
  RefreshConfig
  & $PhpPath (Join-Path $ToirAppPath 'artisan') toir:mail-check
  if($LASTEXITCODE -ne 0){throw 'TOIR SMTP configuration validation failed'}
  if($TestRecipient -ne ''){
    & $PhpPath (Join-Path $ToirAppPath 'artisan') toir:mail-check ('--send-to='+$TestRecipient)
    if($LASTEXITCODE -ne 0){throw 'TOIR SMTP transport rejected the test message'}
  }else{
    Write-Output 'TOIR_SMTP_SEND_NOT_TESTED: supply -TestRecipient to verify transport'
  }
  Write-Output 'TOIR_SMTP_SETTINGS_APPLIED_BACKUP_SAVED'
  Write-Output 'Restart only the ITP-TOIR-FastCGI service to refresh long-running workers'
}catch{
  $reason=$_.Exception.Message
  Copy-Item -LiteralPath $backup -Destination $toirPath -Force
  RefreshConfig
  throw ('TOIR_SMTP_ROLLED_BACK: '+$reason)
}
