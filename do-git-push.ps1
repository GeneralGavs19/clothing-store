$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $repo
$log = Join-Path $repo 'do-git-push.log'
"=== $(Get-Date -Format o) ===" | Out-File $log -Encoding utf8
git status 2>&1 | Out-File $log -Append -Encoding utf8
git add frontend/.env.production 2>&1 | Out-File $log -Append -Encoding utf8
git commit -m "Set production API URL to Railway" 2>&1 | Out-File $log -Append -Encoding utf8
git push origin main 2>&1 | Out-File $log -Append -Encoding utf8
"=== DONE ===" | Out-File $log -Append -Encoding utf8
git log -1 --oneline 2>&1 | Out-File $log -Append -Encoding utf8
