# Builds the Zhandui assistant APK with the local Gradle distribution.
# This machine has no internet access, so everything runs --offline against the
# Gradle cache in ~/.gradle. If a dependency is missing from that cache the build
# fails fast instead of hanging on a download.

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $root

$env:JAVA_HOME = 'D:\ja'
$env:Path = "D:\ja\bin;$env:Path"

$sdk = "$env:LOCALAPPDATA\Android\Sdk"
if (-not (Test-Path "$root\local.properties")) {
    Set-Content -Path "$root\local.properties" -Value ("sdk.dir=" + ($sdk.Replace('\', '\\'))) -Encoding ascii
}

$gradleHome = "$env:USERPROFILE\.gradle-dists\gradle-8.13"
$gradleBat = "$gradleHome\bin\gradle.bat"
if (-not (Test-Path $gradleBat)) {
    throw "Gradle not found at $gradleBat. This box is offline; restore the cached distribution first."
}

if (-not (Test-Path "$root\keystore.properties")) {
    throw "keystore.properties is missing. See README.md - without the original keystore the app cannot be installed over older builds."
}

Write-Output "== assembleRelease (offline) =="
# gradle is run through cmd so its stdout/stderr land in a file straight away:
# piping native output into PowerShell 5.1 both buffers it until the very end and
# turns javac's stderr warnings into a terminating NativeCommandError.
$log = Join-Path $root 'build-log.txt'
& cmd.exe /c "`"$gradleBat`" --offline --console=plain -p `"$root`" assembleRelease > `"$log`" 2>&1"
$code = $LASTEXITCODE
Get-Content $log -Tail 30 | ForEach-Object { Write-Output $_ }
if ($code -ne 0) {
    throw "gradle assembleRelease failed with exit code $code, full log: $log"
}

$apk = "$root\app\build\outputs\apk\release\app-release.apk"
if (-not (Test-Path $apk)) {
    throw "build reported success but $apk is missing"
}

$buildTools = Get-ChildItem "$sdk\build-tools" -Directory | Sort-Object Name -Descending | Select-Object -First 1
$apksigner = Join-Path $buildTools.FullName 'apksigner.bat'
$aapt = Join-Path $buildTools.FullName 'aapt.exe'

Write-Output "== signature =="
& cmd.exe /c "`"$apksigner`" verify --verbose `"$apk`" > `"$log`" 2>&1"
if ($LASTEXITCODE -ne 0) {
    Get-Content $log | ForEach-Object { Write-Output $_ }
    throw "apksigner verify failed"
}

Write-Output "== manifest summary =="
# aapt cannot open a path containing non-ascii characters, so verify the original output.
& cmd.exe /c "`"$aapt`" dump badging `"$apk`" > `"$log`" 2>&1"
Get-Content $log -First 12 | ForEach-Object { Write-Output $_ }

$delivered = Join-Path (Split-Path -Parent $root) '战队助手.apk'
Copy-Item $apk $delivered -Force
Write-Output ("== delivered: " + $delivered + " (" + (Get-Item $delivered).Length + " bytes) ==")
