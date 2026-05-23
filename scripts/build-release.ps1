# 打包 WordPress 插件 Release（zip 内须含 WanYesea-AI/ 目录）
$ErrorActionPreference = 'Stop'

$pluginRoot = Split-Path -Parent $PSScriptRoot
$pluginName = 'WanYesea-AI'
$distDir    = Join-Path $pluginRoot 'dist'
$stageDir   = Join-Path $distDir $pluginName
$zipPath    = Join-Path $distDir "$pluginName.zip"

if (Test-Path $distDir) {
    Remove-Item $distDir -Recurse -Force
}
New-Item -ItemType Directory -Path $stageDir -Force | Out-Null

$exclude = @('dist', '.git', '.github', 'scripts')
Get-ChildItem -Path $pluginRoot -Force | Where-Object {
    $exclude -notcontains $_.Name
} | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination $stageDir -Recurse -Force
}

if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}
Compress-Archive -Path $stageDir -DestinationPath $zipPath -Force

Write-Host "Release zip: $zipPath"
Write-Host ""
Write-Host "GitHub Actions (recommended):"
Write-Host "  1. Bump Version in index.php + update_log.md"
Write-Host "  2. git add . ; git commit -m ""vX.Y.Z: summary"" ; git push"
Write-Host "  3. git tag vX.Y.Z ; git push origin vX.Y.Z"
Write-Host "     -> workflow .github/workflows/release.yml builds zip and publishes Release"
Write-Host ""
Write-Host "Manual: upload dist/WanYesea-AI.zip to GitHub Release assets."
