$ErrorActionPreference = 'Stop'
$published = @(Get-Content -LiteralPath (Join-Path $PSScriptRoot 'published.json') -Raw | ConvertFrom-Json)
$issueIds = @{}
foreach ($item in $published) {
    $raw = & gh api ('repos/ZeachDev01/retailmind/issues/' + $item.number) --jq '.id'
    if ($LASTEXITCODE -ne 0) { throw 'Cannot read issue identity' }
    $issueIds[[int]$item.index] = [long]$raw
}
foreach ($item in $published) {
    foreach ($blockIndex in $item.blocks) {
        & gh api --method POST ('repos/ZeachDev01/retailmind/issues/' + $item.number + '/dependencies/blocked_by') -F ('issue_id=' + $issueIds[[int]$blockIndex]) --silent
        if ($LASTEXITCODE -ne 0) { throw ('Cannot link dependency for #' + $item.number) }
        Write-Output ('#' + $item.number + ' blocked by #' + $published[[int]$blockIndex - 1].number)
    }
}
foreach ($item in $published) {
    & gh issue view $item.number --repo ZeachDev01/retailmind --json number,title,labels,blockedBy --jq '{number,title,labels:[.labels[].name],blockedBy}'
    if ($LASTEXITCODE -ne 0) { throw 'Verification failed' }
}
