#requires -Version 5.1
# Read-only TOIR process audit. No secrets or .env content are displayed.
[CmdletBinding()]
param(
  [Parameter(Mandatory=$true)][string]$PhpPath,
  [Parameter(Mandatory=$true)][string]$ToirAppPath
)
$ErrorActionPreference='Stop'
if(!(Test-Path -LiteralPath $PhpPath -PathType Leaf)){throw 'PHP not found'}
if(!(Test-Path -LiteralPath (Join-Path $ToirAppPath 'artisan'))){throw 'TOIR artisan not found'}
Get-Service -Name 'ITP-TOIR-FastCGI' -ErrorAction SilentlyContinue |
  Select-Object Name,Status | Format-Table -AutoSize
foreach($name in @('ITP-TOIR-ChatWorker','ITP-TOIR-Overdue','ITP-TOIR-Reverb')){
  Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue |
    Select-Object TaskName,State | Format-Table -AutoSize
}
& $PhpPath (Join-Path $ToirAppPath 'artisan') toir:mail-check
if($LASTEXITCODE -ne 0){throw 'TOIR SMTP not ready'}
Write-Output 'TOIR_RUNTIME_AUDIT_COMPLETE'
