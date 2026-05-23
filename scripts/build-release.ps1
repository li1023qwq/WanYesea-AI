# 打包 WordPress 插件 Release（zip 内须含 WanYesea-AI/ 目录）
# 排除规则见插件根目录 .distignore
$ErrorActionPreference = 'Stop'

$pluginRoot = Split-Path -Parent $PSScriptRoot
$pluginName = 'WanYesea-AI'
$distDir    = Join-Path $pluginRoot 'dist'
$stageDir   = Join-Path $distDir $pluginName
$zipPath    = Join-Path $distDir "$pluginName.zip"
$ignoreFile = Join-Path $pluginRoot '.distignore'

function Get-ReleaseExcludePatterns {
    param([string]$Path)

    if (-not (Test-Path $Path)) {
        return @('dist', '.git', '.github', 'scripts')
    }

    $patterns = @()
    foreach ($line in Get-Content -Path $Path -Encoding UTF8) {
        $line = $line.Trim()
        if ($line -eq '' -or $line.StartsWith('#')) {
            continue
        }
        $patterns += ($line -replace '\\', '/').TrimStart('/')
    }

    return $patterns | Select-Object -Unique
}

function Test-ReleaseExcluded {
    param(
        [string[]]$Patterns,
        [string]$RelativePath
    )

    $rel = ($RelativePath -replace '\\', '/').TrimStart('/')
    if ($rel -eq '') {
        return $false
    }

    foreach ($pattern in $Patterns) {
        $p = ($pattern -replace '\\', '/').TrimStart('/')
        if ($p -eq '') {
            continue
        }

        if ($rel -eq $p) {
            return $true
        }

        if ($rel.StartsWith("$p/")) {
            return $true
        }

        $top = ($rel -split '/')[0]
        if ($top -eq $p) {
            return $true
        }
    }

    return $false
}

function Copy-ReleaseTree {
    param(
        [string]$SourceRoot,
        [string]$DestRoot,
        [string[]]$Patterns,
        [string]$RelativePrefix = ''
    )

    Get-ChildItem -Path $SourceRoot -Force | ForEach-Object {
        $rel = if ($RelativePrefix -eq '') { $_.Name } else { "$RelativePrefix/$($_.Name)" }

        if (Test-ReleaseExcluded -Patterns $Patterns -RelativePath $rel) {
            return
        }

        $dest = Join-Path $DestRoot $_.Name
        if ($_.PSIsContainer) {
            if (-not (Test-Path $dest)) {
                New-Item -ItemType Directory -Path $dest -Force | Out-Null
            }
            Copy-ReleaseTree -SourceRoot $_.FullName -DestRoot $dest -Patterns $Patterns -RelativePrefix $rel
        } else {
            Copy-Item -Path $_.FullName -Destination $dest -Force
        }
    }
}

$excludePatterns = Get-ReleaseExcludePatterns -Path $ignoreFile

if (Test-Path $distDir) {
    Remove-Item $distDir -Recurse -Force
}
New-Item -ItemType Directory -Path $stageDir -Force | Out-Null

Copy-ReleaseTree -SourceRoot $pluginRoot -DestRoot $stageDir -Patterns $excludePatterns

if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}
Compress-Archive -Path $stageDir -DestinationPath $zipPath -Force

Write-Host "Release zip: $zipPath"
Write-Host "Exclude rules: $ignoreFile ($($excludePatterns.Count) patterns)"
Write-Host ""
Write-Host "GitHub Actions (recommended):"
Write-Host "  1. Bump Version in index.php + update_log.md"
Write-Host "  2. git add . ; git commit -m ""vX.Y.Z: summary"" ; git push"
Write-Host "  3. git tag vX.Y.Z ; git push origin vX.Y.Z"
Write-Host "     -> workflow .github/workflows/release.yml builds zip and publishes Release"
Write-Host ""
Write-Host "Edit .distignore to add or remove excluded paths before packaging."
