$ErrorActionPreference = 'Stop'
$draftRoot = $PSScriptRoot
$draft = Get-Content -LiteralPath (Join-Path $draftRoot 'tickets.json') -Raw | ConvertFrom-Json
$ledgerPath = Join-Path $draftRoot 'published.json'
$published = @()
if (Test-Path -LiteralPath $ledgerPath) { $published = @(Get-Content -LiteralPath $ledgerPath -Raw | ConvertFrom-Json) }
for ($i = $published.Count; $i -lt $draft.tickets.Count; $i++) {
    $ticket = $draft.tickets[$i]
    $blockLines = @($ticket.blocks | ForEach-Object { $blocker = $published[[int]$_ - 1]; '- #' + $blocker.number + ': ' + $blocker.title })
    if ($blockLines.Count -eq 0) { $blockLines = @('None (can start immediately).') }
    $criteria = @($ticket.criteria | ForEach-Object { '- [ ] ' + $_ }) -join "`n"
    $body = $draft.header + "## What to build`n`n" + $ticket.build + "`n`n## Acceptance criteria`n`n" + $criteria + "`n`n## Blocked by`n`n" + ($blockLines -join "`n") + "`n"
    $bodyPath = Join-Path $draftRoot ('ticket-' + ($i + 1) + '.md')
    [System.IO.File]::WriteAllText($bodyPath, $body, [System.Text.UTF8Encoding]::new($false))
    $created = & gh issue create --repo ZeachDev01/retailmind --title $ticket.title --body-file $bodyPath --label ready-for-agent
    if ($LASTEXITCODE -ne 0) { throw ('Creation failed for ticket ' + ($i + 1)) }
    $url = ($created | Select-Object -Last 1).Trim()
    if ($url -notmatch '/issues/(\d+)$') { throw ('Unexpected creation result: ' + $url) }
    $published += [pscustomobject]@{ index = $i + 1; number = [int]$Matches[1]; title = $ticket.title; url = $url; blocks = @($ticket.blocks) }
    [System.IO.File]::WriteAllText($ledgerPath, (ConvertTo-Json -InputObject @($published) -Depth 8), [System.Text.UTF8Encoding]::new($false))
    Write-Output $url
}
