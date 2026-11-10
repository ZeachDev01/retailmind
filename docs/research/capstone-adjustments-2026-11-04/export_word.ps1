param([string]$InputFile, [string]$OutputPdf)
$ErrorActionPreference = 'Stop'
$InputFile = [IO.Path]::GetFullPath($InputFile)
$OutputPdf = [IO.Path]::GetFullPath($OutputPdf)
$word = $null
$document = $null
try {
    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $word.DisplayAlerts = 0
    $word.AutomationSecurity = 3
    $word.ScreenUpdating = $false
    $document = $word.Documents.Open($InputFile, $false, $true, $false)
    $document.Repaginate()
    $document.ExportAsFixedFormat($OutputPdf, 17)
    Write-Output ('Pages: ' + $document.ComputeStatistics(2))
    if (-not (Test-Path -LiteralPath $OutputPdf)) { throw 'PDF export missing' }
} finally {
    if ($null -ne $document) { $document.Close(0); [void][Runtime.InteropServices.Marshal]::ReleaseComObject($document) }
    if ($null -ne $word) { $word.Quit(); [void][Runtime.InteropServices.Marshal]::ReleaseComObject($word) }
}
