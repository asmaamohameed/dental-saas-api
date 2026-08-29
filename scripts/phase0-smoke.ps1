# Phase 0 smoke script — validates backend API assumptions from FRONTEND_INTEGRATION_PLAN.md
# Usage:  powershell -File scripts/phase0-smoke.ps1
# Prereq: php artisan serve --port=8000  +  php artisan migrate:fresh --seed (sqlite or docker pgsql)
param([string]$Base = 'http://127.0.0.1:8000/api/v1')

$ErrorActionPreference = 'Continue'
$tmp = Join-Path $env:TEMP 'phase0-smoke'
New-Item -ItemType Directory -Force -Path $tmp | Out-Null

function Invoke-Api {
    param([string]$Method = 'GET', [string]$Path, [string]$Token = $null, [object]$Body = $null)
    $curlArgs = @('-s', '-X', $Method, "$Base$Path", '-H', 'Accept: application/json', '-w', "`n__HTTP__:%{http_code}")
    if ($Token) { $curlArgs += @('-H', "Authorization: Bearer $Token") }
    if ($null -ne $Body) {
        $f = Join-Path $tmp 'body.json'
        [System.IO.File]::WriteAllText($f, ($Body | ConvertTo-Json -Compress -Depth 6))
        $curlArgs += @('-H', 'Content-Type: application/json', '-d', "@$f")
    }
    $raw = (& curl.exe @curlArgs 2>$null) -join "`n"
    $code = 0
    if ($raw -match '(?s)__HTTP__:(\d+)\s*$') { $code = [int]$Matches[1]; $json = $raw -replace '(?s)`n__HTTP__:\d+\s*$', '' } else { $json = $raw }
    return [pscustomobject]@{ Code = $code; Body = $json }
}

function Show($label, $r, [switch]$Dump) {
    $b = $r.Body
    if ($b.Length -gt 400) { $b = $b.Substring(0, 400) + ' ...[truncated]' }
    Write-Host ("{0} -> HTTP {1}" -f $label, $r.Code)
    if ($Dump) { Write-Host "    $b" }
}

Write-Host "== BASE: $Base ==" -ForegroundColor Cyan

# --- 1. Login as each seeded role ---
$tokens = @{}
foreach ($role in @('owner', 'doctor', 'reception')) {
    $email = "$role@smileclinic.com"
    $r = Invoke-Api -Method POST -Path '/auth/login' -Body @{ email = $email; password = 'password'; device_name = 'web' }
    if ($r.Code -eq 200) {
        $tokens[$role] = ($r.Body | ConvertFrom-Json).data.access_token
        Show "POST /auth/login ($role)" $r
    } else {
        Show "POST /auth/login ($role) FAILED" $r -Dump
    }
}
if (-not $tokens.owner) { Write-Host 'FATAL: no owner token, aborting' -ForegroundColor Red; exit 1 }

# --- G: unknown email login (user-enumeration check) ---
$r = Invoke-Api -Method POST -Path '/auth/login' -Body @{ email = 'nobody@nowhere.com'; password = 'password' }
Show 'G: POST /auth/login (unknown email)' $r -Dump

# --- 2. /auth/me ---
$r = Invoke-Api -Path '/auth/me' -Token $tokens.owner
Show 'GET /auth/me (owner)' $r -Dump

# --- 3. Patients: list per role + A-shape check ---
foreach ($role in @('owner', 'doctor', 'reception')) {
    $r = Invoke-Api -Path '/patients?per_page=1' -Token $tokens[$role]
    $shape = ''
    try { $p = $r.Body | ConvertFrom-Json; $shape = "keys=$(@($p.data.PSObject.Properties.Name) -join ',')" } catch {}
    Show "A: GET /patients?per_page=1 ($role)  [$shape]" $r
}

# --- B: medical_history / notes visibility per role ---

# --- B: appointments list per role ---
foreach ($role in @('owner', 'doctor', 'reception')) {
    $r = Invoke-Api -Path '/appointments' -Token $tokens[$role]
    Show "B: GET /appointments ($role)" $r
    if ($r.Code -ne 200) { Write-Host "    $($r.Body)" }
}

# --- B: tooth records + odontogram per role ---
$patientId = $null
$r = Invoke-Api -Path '/patients?per_page=1' -Token $tokens.owner
try { $patientId = ($r.Body | ConvertFrom-Json).data.items[0].id } catch {}
Write-Host "    (seeded patient id: $patientId)"
foreach ($role in @('owner', 'doctor', 'reception')) {
    $r = Invoke-Api -Path "/patients/$patientId/tooth-records" -Token $tokens[$role]
    Show "B: GET /patients/{id}/tooth-records ($role)" $r
    if ($r.Code -ne 200) { Write-Host "    $($r.Body)" }
    $r = Invoke-Api -Path "/patients/$patientId/odontogram" -Token $tokens[$role]
    Show "B: GET /patients/{id}/odontogram ($role)" $r
    if ($r.Code -ne 200) { Write-Host "    $($r.Body)" }
}

# --- C: patient delete as receptionist (create throwaway patient first as owner) ---
$r = Invoke-Api -Method POST -Path '/patients' -Token $tokens.owner -Body @{ full_name = 'Smoke Delete Me'; phone = '+1000000001'; date_of_birth = '2000-01-01'; gender = 'male' }
$throwaway = $null
try { $throwaway = ($r.Body | ConvertFrom-Json).data.id } catch {}
Show "C: POST /patients (owner, throwaway)" $r
if ($throwaway) {
    $r = Invoke-Api -Method DELETE -Path "/patients/$throwaway" -Token $tokens.reception
    Show "C: DELETE /patients/{id} (receptionist)" $r -Dump
}

# --- D/A: services list — is_other present? pagination shape? ---
$r = Invoke-Api -Path '/services' -Token $tokens.owner
$svcInfo = ''
try {
    $p = $r.Body | ConvertFrom-Json
    $rows = if ($p.data.data) { $p.data.data } elseif ($p.data.items) { $p.data.items } else { @() }
    $other = $rows | Where-Object { $_.is_other -eq $true }
    $svcInfo = "rows=$($rows.Count); is_other_found=$([bool]$other); shape=$(@($p.data.PSObject.Properties.Name) -join ',')"
} catch { $svcInfo = 'parse-failed' }
Show "D/A: GET /services (owner)  [$svcInfo]" $r -Dump

# --- A: invoices list shape ---
$r = Invoke-Api -Path '/invoices' -Token $tokens.owner
$invShape = ''
try { $p = $r.Body | ConvertFrom-Json; $invShape = "shape=$(@($p.data.PSObject.Properties.Name) -join ',')" } catch {}
Show "A: GET /invoices (owner)  [$invShape]" $r

# --- J: inventory deactivate -> list -> restore ---
$r = Invoke-Api -Method POST -Path '/inventory-items' -Token $tokens.owner -Body @{ name = 'Smoke Gloves'; unit = 'box'; quantity_in_stock = 10; low_stock_threshold = 2 }
$itemId = $null
try { $itemId = ($r.Body | ConvertFrom-Json).data.id } catch {}
Show "J: POST /inventory-items (owner)" $r
if (-not $itemId) { Write-Host "    $($r.Body)" }
if ($itemId) {
    $r = Invoke-Api -Method DELETE -Path "/inventory-items/$itemId" -Token $tokens.owner
    Show 'J: DELETE /inventory-items/{id} (owner = deactivate)' $r -Dump
    $r = Invoke-Api -Path '/inventory-items' -Token $tokens.owner
    $stillListed = 'parse-failed'
    try { $stillListed = [bool](($r.Body | ConvertFrom-Json).data.items | Where-Object { $_.id -eq $itemId }) } catch {}
    Write-Host "    deactivated item still in GET list: $stillListed"
    $r = Invoke-Api -Path '/inventory-items?include_inactive=1' -Token $tokens.owner
    $listedInactive = 'parse-failed'
    try { $listedInactive = [bool](($r.Body | ConvertFrom-Json).data.items | Where-Object { $_.id -eq $itemId }) } catch {}
    Show 'J: GET /inventory-items?include_inactive=1' $r
    Write-Host "    deactivated item listed with include_inactive=1: $listedInactive"
    $r = Invoke-Api -Method PATCH -Path "/inventory-items/$itemId/restore" -Token $tokens.owner
    Show 'J: PATCH /inventory-items/{id}/restore (owner)' $r -Dump
}

# --- H: inventory update with unchanged name (unique-rule false positive check) ---
$r = Invoke-Api -Method POST -Path '/inventory-items' -Token $tokens.owner -Body @{ name = 'Smoke Masks'; unit = 'box'; quantity_in_stock = 5; low_stock_threshold = 1 }
$hId = $null
try { $hId = ($r.Body | ConvertFrom-Json).data.id } catch {}
if ($hId) {
    $r = Invoke-Api -Method PUT -Path "/inventory-items/$hId" -Token $tokens.owner -Body @{ name = 'Smoke Masks'; unit = 'box'; quantity_in_stock = 6; low_stock_threshold = 1 }
    Show 'H: PUT /inventory-items/{id} unchanged name' $r -Dump
    Invoke-Api -Method DELETE -Path "/inventory-items/$hId" -Token $tokens.owner | Out-Null
}

# --- K: transaction endpoint also requires inventory_item_id in body ---
if ($itemId) {
    $r = Invoke-Api -Method POST -Path "/inventory-items/$itemId/transactions" -Token $tokens.owner -Body @{ type = 'in'; quantity = 1; reason = 'smoke' }
    Show 'K: POST transactions WITHOUT inventory_item_id in body' $r -Dump
    $r = Invoke-Api -Method POST -Path "/inventory-items/$itemId/transactions" -Token $tokens.owner -Body @{ type = 'in'; quantity = 1; reason = 'smoke'; inventory_item_id = $itemId }
    Show 'K: POST transactions WITH inventory_item_id in body' $r -Dump
    Invoke-Api -Method DELETE -Path "/inventory-items/$itemId" -Token $tokens.owner | Out-Null
}

Write-Host '== DONE ==' -ForegroundColor Cyan


foreach ($role in @('owner', 'doctor', 'reception')) {
    $r = Invoke-Api -Path '/patients?per_page=1' -Token $tokens[$role]
    $mh = 'n/a'
    try { $item = ($r.Body | ConvertFrom-Json).data.items[0]; $mh = "medical_history=$(if ($null -eq $item.medical_history) {'NULL'} else {'SET'}); notes=$(if ($null -eq $item.notes) {'NULL'} else {'SET'})" } catch {}
    Show "B: patient resource fields ($role)  [$mh]" $r
}
