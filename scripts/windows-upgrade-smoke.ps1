# Windows desktop package UPGRADE smoke. Runs on a Windows machine against the
# candidate package zip, for every release in the published-release window
# (the newest stable releases plus the newest testing prerelease, resolved from
# the GitHub releases API like tests/fixtures/releases.py does).
#
# The desktop edition has no in-app updater; the documented upgrade is
# "extract the new zip, copy the old app\data folder in" (windows/README).
# Per old release this script does exactly that:
#   1. extract the OLD package, boot it, walk its wizard (rail-less), create
#      products and an API key with scripts/upgrade-smoke.php seed, run a
#      cron pass, stop it;
#   2. extract a fresh copy of the CANDIDATE package, copy the old app\data
#      into it, boot it;
#   3. prove the merchant's world survived: upgrade-smoke.php verify (login
#      with the old password, store, products, API key), a cron pass against
#      the migrated database, then stop it.
# No payments here: the regtest stack has no Windows fixtures. The database
# half of this flow is also exercised with payments on Linux by
# tests/upgrade/test_data_dir_carry_over.py.
#
# Downloads are cached under tests/bin/.cache/releases/<tag>/ (gitignored) so
# repeated runs never hit GitHub twice for the same release.
#
# Usage (from a repo checkout, PowerShell 7):
#   pwsh scripts/windows-upgrade-smoke.ps1 -Zip build/barebits-windows.zip
#   pwsh scripts/windows-upgrade-smoke.ps1 -Zip build/barebits-windows.zip -From v1.8,v1.7
#   -StableCount N   window size (default 5)
#   -From a,b        pin the window to these tags (debugging / offline)
# Build the candidate zip first: scripts/build-windows-desktop.sh (Linux/WSL)
# or take it from a release's assets.

param(
    [Parameter(Mandatory = $true)][string]$Zip,
    [int]$StableCount = 5,
    [string]$From = ''
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$DriverPhp = Join-Path $PSScriptRoot 'upgrade-smoke.php'
if (-not (Test-Path $DriverPhp)) { throw "upgrade-smoke.php not found next to this script" }
$Zip = (Resolve-Path $Zip).Path
$CacheDir = Join-Path $RepoRoot 'tests/bin/.cache/releases'
New-Item -ItemType Directory -Path $CacheDir -Force | Out-Null

# Same hygiene as windows-smoke.ps1: no live BareBits endpoints from a smoke
# install, fast helper ticks. The old packages' own .bat set the updater kill
# switch and the desktop flag for everything they start.
$env:CASHUPAY_UPDATER_DISABLED = '1'
$env:CASHUPAY_DEV_FEE_BRANCH_ENDPOINT = 'disabled'
$env:CASHUPAY_USAGE_STATS_ENDPOINT = 'disabled'
$env:CASHUPAY_HELPER_TICK = '2'

$Password = 'upgrade-admin-pw-1234'
$StoreName = 'Upgrade Store'
$ProductCount = 3

# --- helpers (shared shape with windows-smoke.ps1) ----------------------------

function Wait-Http([string]$Url) {
    $last = 'no response at all'
    for ($i = 0; $i -lt 120; $i++) {
        try {
            $resp = Invoke-WebRequest $Url -MaximumRedirection 5 -SkipHttpErrorCheck
            if ($resp.StatusCode -eq 200) { return $resp }
            $body = [string]$resp.Content
            $last = "HTTP $($resp.StatusCode): " + $body.Substring(0, [Math]::Min(500, $body.Length))
        } catch {
            $last = $_.Exception.Message
        }
        Start-Sleep -Milliseconds 500
    }
    throw "no HTTP 200 from $Url within 60s; last: $last"
}

$script:DiagOut = $null
$script:DiagErr = $null
function Start-Launcher([string]$Root, [int]$Port) {
    $tag = [IO.Path]::GetRandomFileName().Substring(0, 8)
    $script:DiagOut = Join-Path (Get-Location).Path "upgrade-launcher-$tag.out.log"
    $script:DiagErr = Join-Path (Get-Location).Path "upgrade-launcher-$tag.err.log"
    Start-Process -FilePath (Join-Path $Root 'BareBits.bat') -ArgumentList @("$Port") `
        -WindowStyle Hidden -RedirectStandardOutput $script:DiagOut `
        -RedirectStandardError $script:DiagErr -PassThru | Out-Null
}

function Get-ServerProcs([string]$Root) {
    $esc = [regex]::Escape($Root)
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
        Where-Object { $_.CommandLine -match '-S 127\.0\.0\.1' -and $_.CommandLine -match $esc }
}

function Get-HelperProcs([string]$Root) {
    $esc = [regex]::Escape($Root)
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
        Where-Object { $_.CommandLine -match 'desktop-helper\.php' -and $_.CommandLine -match $esc }
}

function Stop-Package([string]$Root) {
    $server = Get-ServerProcs $Root
    if (-not $server) { throw "server process not found under $Root" }
    $server | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
    for ($i = 0; $i -lt 30; $i++) {
        Start-Sleep -Seconds 1
        if (-not (Get-HelperProcs $Root)) { return }
    }
    throw "helper did not shut itself down after server stop under $Root"
}

function Show-Diagnostics([string]$Root) {
    foreach ($pair in @(@('launcher stdout', $script:DiagOut), @('launcher stderr', $script:DiagErr))) {
        Write-Host "---- $($pair[0]) ----"
        try { if ($pair[1] -and (Test-Path $pair[1])) { Get-Content $pair[1] | Write-Host } } catch { Write-Host "(unreadable: $_)" }
    }
    $errLog = Join-Path $Root 'app/data/php-error.log'
    try {
        if (Test-Path $errLog) {
            Write-Host "---- app php-error.log (tail) ----"
            Get-Content $errLog -Tail 40 | Write-Host
        }
    } catch { Write-Host "(unreadable: $_)" }
}

# Run the PHP driver with the package's own php.exe; return its RESULT object.
function Invoke-Driver([string]$Root, [string[]]$DriverArgs) {
    $php = Join-Path $Root 'php/php.exe'
    $ini = Join-Path $Root 'php/php.ini'
    $out = & $php -c $ini $DriverPhp @DriverArgs 2>&1
    if ($LASTEXITCODE -ne 0) {
        $out | Write-Host
        throw "upgrade-smoke.php $($DriverArgs[0]) failed (rc=$LASTEXITCODE)"
    }
    $out | Where-Object { "$_" -like 'ok - *' } | ForEach-Object { Write-Host "  $_" }
    $result = $out | Where-Object { "$_" -like 'RESULT=*' } | Select-Object -Last 1
    if (-not $result) { throw "upgrade-smoke.php printed no RESULT line" }
    return ("$result".Substring(7) | ConvertFrom-Json)
}

function Invoke-CronRunner([string]$Root) {
    $php = Join-Path $Root 'php/php.exe'
    $ini = Join-Path $Root 'php/php.ini'
    & $php -c $ini (Join-Path $Root 'cron-runner.php')
    if ($LASTEXITCODE -ne 0) { throw "cron-runner.php failed under $Root" }
}

# --- the release window -------------------------------------------------------

function Get-ReleaseWindow {
    $headers = @{ 'User-Agent' = 'cashupayserver-tests/upgrade-tier'; 'Accept' = 'application/vnd.github+json' }
    $token = $env:GITHUB_TOKEN; if (-not $token) { $token = $env:GH_TOKEN }
    if ($token) { $headers['Authorization'] = "Bearer $token" }
    $listingFile = Join-Path $CacheDir 'listing.json'
    $listing = $null
    if ((Test-Path $listingFile) -and ((Get-Date) - (Get-Item $listingFile).LastWriteTime).TotalSeconds -lt 3600) {
        $listing = Get-Content $listingFile -Raw | ConvertFrom-Json
    } else {
        try {
            $raw = Invoke-WebRequest 'https://api.github.com/repos/BareBits/cashupayserver/releases?per_page=50' -Headers $headers
            Set-Content -Path $listingFile -Value $raw.Content -NoNewline
            $listing = $raw.Content | ConvertFrom-Json
        } catch {
            if (Test-Path $listingFile) {
                Write-Host "GitHub listing unavailable ($($_.Exception.Message)); using the cached listing"
                $listing = Get-Content $listingFile -Raw | ConvertFrom-Json
            } else { throw }
        }
    }
    $listing = @($listing | Where-Object { -not $_.draft })
    if ($From) {
        $want = $From.Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ }
        return @($want | ForEach-Object {
            $tag = $_
            $rel = $listing | Where-Object { $_.tag_name -eq $tag } | Select-Object -First 1
            if (-not $rel) { throw "-From names $tag, which is not a published release" }
            $rel
        })
    }
    $usable = @($listing | Where-Object { $_.assets.name -contains "barebits-windows-$($_.tag_name).zip" })
    $stable = @($usable | Where-Object { -not $_.prerelease } | Select-Object -First $StableCount)
    $testing = @($usable | Where-Object { $_.prerelease } | Select-Object -First 1)
    return @($stable + $testing)
}

function Get-ReleaseWindowsZip($Release) {
    $name = "barebits-windows-$($Release.tag_name).zip"
    $asset = $Release.assets | Where-Object { $_.name -eq $name } | Select-Object -First 1
    if (-not $asset) { throw "release $($Release.tag_name) has no $name asset" }
    $dir = Join-Path $CacheDir $Release.tag_name
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    $dest = Join-Path $dir $name
    $sumsAsset = $Release.assets | Where-Object { $_.name -eq 'SHA256SUMS' } | Select-Object -First 1
    $expected = $null
    if ($sumsAsset) {
        $sums = Join-Path $dir 'SHA256SUMS'
        if (-not (Test-Path $sums)) { Invoke-WebRequest $sumsAsset.browser_download_url -OutFile $sums }
        $line = Get-Content $sums | Where-Object { $_ -match "\s\*?$([regex]::Escape($name))$" } | Select-Object -First 1
        if ($line) { $expected = ($line -split '\s+')[0].ToLower() }
    }
    if ((Test-Path $dest) -and $expected -and (Get-FileHash $dest -Algorithm SHA256).Hash.ToLower() -ne $expected) {
        Write-Host "cached $name fails its checksum; re-downloading"
        Remove-Item $dest -Force
    }
    if (-not (Test-Path $dest)) {
        Write-Host "downloading $name ..."
        Invoke-WebRequest $asset.browser_download_url -OutFile "$dest.partial"
        Move-Item "$dest.partial" $dest -Force
        if ($expected -and (Get-FileHash $dest -Algorithm SHA256).Hash.ToLower() -ne $expected) {
            Remove-Item $dest -Force
            throw "checksum mismatch for $name"
        }
    }
    return $dest
}

# --- main ---------------------------------------------------------------------

$window = Get-ReleaseWindow
if ($window.Count -eq 0) { throw "release window is empty" }
Write-Host ("upgrade window: " + (($window | ForEach-Object { $_.tag_name }) -join ', '))

$work = Join-Path (Get-Location).Path 'upgrade-smoke'
if (Test-Path $work) { Remove-Item $work -Recurse -Force }
New-Item -ItemType Directory -Path $work | Out-Null

$port = 9300
$currentRoot = $null
trap {
    Write-Host "UPGRADE SMOKE FAILED: $_"
    if ($currentRoot) { Show-Diagnostics $currentRoot }
    break
}

foreach ($rel in $window) {
    $tag = $rel.tag_name
    Write-Host ""
    Write-Host "===== $tag -> candidate ====="
    $oldZip = Get-ReleaseWindowsZip $rel

    # --- 1. the old package, lived in -------------------------------------------
    $oldBase = Join-Path $work "$tag-old"
    Expand-Archive $oldZip -DestinationPath $oldBase
    $oldRoot = (Resolve-Path (Join-Path $oldBase 'BareBits')).Path
    $currentRoot = $oldRoot
    $port++
    $env:CASHUPAY_BROWSER_CMD = "cmd /c echo {url} > `"$oldBase\browser-opened.txt`""
    Start-Launcher $oldRoot $port
    Wait-Http "http://127.0.0.1:$port/" | Out-Null
    $seed = Invoke-Driver $oldRoot @('seed', "http://127.0.0.1:$port", "--password=$Password", "--store=$StoreName", "--products=$ProductCount")
    Invoke-CronRunner $oldRoot
    Stop-Package $oldRoot
    Write-Host "  $tag seeded (store $($seed.store_id), version $($seed.current_version))"

    # --- 2. the candidate package with the old data folder ----------------------
    $newBase = Join-Path $work "$tag-new"
    Expand-Archive $Zip -DestinationPath $newBase
    $newRoot = (Resolve-Path (Join-Path $newBase 'BareBits')).Path
    $currentRoot = $newRoot
    $newData = Join-Path $newRoot 'app\data'
    if (Test-Path $newData) { Remove-Item $newData -Recurse -Force }
    Copy-Item (Join-Path $oldRoot 'app\data') $newData -Recurse
    $port++
    $env:CASHUPAY_BROWSER_CMD = "cmd /c echo {url} > `"$newBase\browser-opened.txt`""
    Start-Launcher $newRoot $port
    Wait-Http "http://127.0.0.1:$port/" | Out-Null

    # --- 3. the merchant's world survived ---------------------------------------
    $titles = ($seed.products | ForEach-Object { $_.title }) -join '|'
    $verify = Invoke-Driver $newRoot @('verify', "http://127.0.0.1:$port", "--password=$Password",
        "--store-id=$($seed.store_id)", "--api-key=$($seed.api_key)", "--store-name=$StoreName",
        "--products=$ProductCount", "--product-titles=$titles", '--invoices=0')
    if ($verify.current_version -eq $seed.current_version -and $verify.current_sha -eq $seed.current_sha) {
        throw "the candidate reports the same build as $tag ($($seed.current_sha)); is -Zip really the new package?"
    }
    Invoke-CronRunner $newRoot
    $stampQuery = '$db = new PDO("sqlite:" . $argv[1]); $v = $db->query("SELECT value FROM config WHERE key = ''last_external_cron_at''")->fetchColumn(); echo $v === false ? "" : $v;'
    $stamp = & (Join-Path $newRoot 'php/php.exe') -c (Join-Path $newRoot 'php/php.ini') -r $stampQuery (Join-Path $newRoot 'app/data/cashupay.sqlite')
    if (-not $stamp) { throw "cron pass on the carried-over database did not stamp last_external_cron_at" }
    Stop-Package $newRoot
    $currentRoot = $null
    Write-Host "  $tag -> $($verify.current_version) ($($verify.current_sha.Substring(0, 12))): OK"

    Remove-Item $oldBase -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item $newBase -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Host ""
Write-Host "windows-upgrade-smoke: all $($window.Count) upgrades passed"
