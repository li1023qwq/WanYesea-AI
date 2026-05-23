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
Write-Host "Upload this file to GitHub Release assets (tag e.g. v1.2.2)."
