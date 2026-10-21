[CmdletBinding()]
param(
    [string] $Sp500Input = '',
    [string] $GoldInput = '',
    [string] $GoldArchive = '',
    [string] $EcbInput = '',
    [string] $OutputDirectory = '',
    [string] $From = '2016-08-02',
    [string] $To = '2026-08-02'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Invariant = [Globalization.CultureInfo]::InvariantCulture
$ProjectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$Sp500Input = if ($Sp500Input) { $Sp500Input } else { Join-Path $ProjectRoot 'SP500.csv' }
$GoldInput = if ($GoldInput) { $GoldInput } else { Join-Path $ProjectRoot 'gold_prices_10y.csv' }
$OutputDirectory = if ($OutputDirectory) { $OutputDirectory } else { Join-Path $ProjectRoot 'var\import' }

function Convert-IsoDate([string] $Value, [string] $Name) {
    $parsed = [datetime]::MinValue
    if (-not [datetime]::TryParseExact(
        $Value,
        'yyyy-MM-dd',
        $Invariant,
        [Globalization.DateTimeStyles]::None,
        [ref] $parsed
    )) {
        throw "$Name muss dem Format JJJJ-MM-TT entsprechen."
    }
    return $parsed.Date
}

function Convert-PositiveNumber([object] $Value) {
    $number = 0.0
    $valid = [double]::TryParse(
        [string] $Value,
        [Globalization.NumberStyles]::Float,
        $Invariant,
        [ref] $number
    )
    if (-not $valid -or [double]::IsNaN($number) -or [double]::IsInfinity($number) -or $number -le 0) {
        return $null
    }
    return $number
}

function Read-ZipEntryText($Archive, [string] $Name) {
    $entry = $Archive.GetEntry($Name)
    if ($null -eq $entry) {
        throw "Im GLD-Archiv fehlt $Name."
    }
    $reader = [IO.StreamReader]::new($entry.Open())
    try {
        return $reader.ReadToEnd()
    } finally {
        $reader.Dispose()
    }
}

function Save-RemoteFile([string] $Uri, [string] $Destination) {
    $curl = Get-Command 'curl.exe' -ErrorAction SilentlyContinue
    if ($null -ne $curl) {
        & $curl.Source '--fail' '--location' '--silent' '--show-error' '--output' $Destination $Uri
        if ($LASTEXITCODE -ne 0) {
            throw "Download fehlgeschlagen: $Uri"
        }
        return
    }
    Invoke-WebRequest -UseBasicParsing -Uri $Uri -OutFile $Destination
}

function Get-XmlAttribute($Node, [string] $Name) {
    $attribute = $Node.Attributes[$Name]
    if ($null -eq $attribute) {
        return ''
    }
    return [string] $attribute.Value
}

function Get-XlsxCellText($Cell, [Collections.Generic.List[string]] $SharedStrings) {
    $valueNode = $Cell.SelectSingleNode('./*[local-name()="v"]')
    if ($null -eq $valueNode) {
        return ''
    }
    $value = [string] $valueNode.InnerText
    if ((Get-XmlAttribute $Cell 't') -eq 's') {
        return $SharedStrings[[int] $value]
    }
    return $value
}

function Read-OfficialGldArchive([string] $Path, [datetime] $Start, [datetime] $End) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [IO.Compression.ZipFile]::OpenRead($Path)
    try {
        [xml] $sharedXml = Read-ZipEntryText $archive 'xl/sharedStrings.xml'
        $strings = [Collections.Generic.List[string]]::new()
        foreach ($item in $sharedXml.sst.si) {
            $textNodes = @($item.SelectNodes('.//*[local-name()="t"]'))
            $strings.Add((($textNodes | ForEach-Object { [string] $_.InnerText }) -join ''))
        }

        [xml] $workbookXml = Read-ZipEntryText $archive 'xl/workbook.xml'
        $sheet = @($workbookXml.workbook.sheets.sheet | Where-Object {
            [string] $_.name -eq 'US GLD Historical Archive'
        })
        if ($sheet.Count -ne 1) {
            throw 'Das offizielle GLD-Datenblatt wurde im Archiv nicht gefunden.'
        }

        [xml] $sheetXml = Read-ZipEntryText $archive 'xl/worksheets/sheet2.xml'
        $result = @{}
        $holidayRows = 0
        foreach ($row in @($sheetXml.worksheet.sheetData.row | Select-Object -Skip 1)) {
            $dateCell = @($row.c | Where-Object { [string] $_.r -match '^A\d+$' })
            $closeCell = @($row.c | Where-Object { [string] $_.r -match '^B\d+$' })
            if ($dateCell.Count -ne 1 -or $closeCell.Count -ne 1) {
                continue
            }
            $dateText = Get-XlsxCellText $dateCell[0] $strings
            $closeText = Get-XlsxCellText $closeCell[0] $strings

            $date = [datetime]::MinValue
            if (-not [datetime]::TryParseExact(
                $dateText,
                'dd-MMM-yyyy',
                $Invariant,
                [Globalization.DateTimeStyles]::None,
                [ref] $date
            )) {
                continue
            }
            if ($date.Date -lt $Start -or $date.Date -gt $End) {
                continue
            }
            $close = Convert-PositiveNumber $closeText
            if ($null -eq $close) {
                $holidayRows++
                continue
            }
            $isoDate = $date.ToString('yyyy-MM-dd')
            if ($result.ContainsKey($isoDate)) {
                throw "Doppelter GLD-Handelstag im offiziellen Archiv: $isoDate"
            }
            $result[$isoDate] = [double] $close
        }
        return [pscustomobject]@{
            Values = $result
            HolidayRows = $holidayRows
        }
    } finally {
        $archive.Dispose()
    }
}

function Read-Sp500([string] $Path, [datetime] $Start, [datetime] $End) {
    $rows = @(Import-Csv -LiteralPath $Path)
    if ($rows.Count -eq 0 -or -not ($rows[0].PSObject.Properties.Name -contains 'observation_date') -or -not ($rows[0].PSObject.Properties.Name -contains 'SP500')) {
        throw 'SP500.csv benötigt die Spalten observation_date und SP500.'
    }
    $values = @{}
    $placeholders = 0
    foreach ($row in $rows) {
        $date = Convert-IsoDate ([string] $row.observation_date) 'S&P-500-Datum'
        if ($date -lt $Start -or $date -gt $End) {
            continue
        }
        $close = Convert-PositiveNumber $row.SP500
        if ($null -eq $close) {
            $placeholders++
            continue
        }
        $isoDate = $date.ToString('yyyy-MM-dd')
        if ($values.ContainsKey($isoDate)) {
            throw "Doppelter S&P-500-Tag: $isoDate"
        }
        $values[$isoDate] = [double] $close
    }
    return [pscustomobject]@{
        Values = $values
        SourceRows = $rows.Count
        Placeholders = $placeholders
    }
}

function Confirm-GoldIdentity(
    [string] $Path,
    [hashtable] $OfficialValues,
    [datetime] $Start,
    [datetime] $End
) {
    $rows = @(Import-Csv -LiteralPath $Path)
    if ($rows.Count -eq 0 -or -not ($rows[0].PSObject.Properties.Name -contains 'Date') -or -not ($rows[0].PSObject.Properties.Name -contains 'Adj Close')) {
        throw 'gold_prices_10y.csv benötigt die Spalten Date und Adj Close.'
    }
    $missing = 0
    $verified = 0
    $maximumDifference = 0.0
    $latest = ''
    foreach ($row in $rows) {
        $parsedDate = Convert-IsoDate ([string] $row.Date) 'Gold-Datum'
        $date = $parsedDate.ToString('yyyy-MM-dd')
        $close = Convert-PositiveNumber $row.'Adj Close'
        if ($null -eq $close) {
            throw "Ungültiger Gold-Schlusskurs am $date."
        }
        if ($date -gt $latest) {
            $latest = $date
        }
        if ($parsedDate -lt $Start -or $parsedDate -gt $End) {
            continue
        }
        if (-not $OfficialValues.ContainsKey($date)) {
            $missing++
            continue
        }
        $difference = [math]::Abs([double] $close - [double] $OfficialValues[$date])
        $maximumDifference = [math]::Max($maximumDifference, $difference)
        $verified++
    }
    if ($verified -eq 0 -or $missing -gt 0 -or $maximumDifference -gt 0.06) {
        throw 'Die gelieferte Gold-Datei lässt sich nicht sicher als GLD-Schlusskursreihe bestätigen.'
    }
    return [pscustomobject]@{
        Rows = $rows.Count
        VerifiedRows = $verified
        LatestDate = $latest
        MaximumDifferenceUsd = $maximumDifference
    }
}

function Read-EcbRates([string] $Path) {
    $rows = @(Import-Csv -LiteralPath $Path)
    if ($rows.Count -eq 0) {
        throw 'Die EZB-Wechselkursdatei ist leer.'
    }
    foreach ($required in @('TIME_PERIOD', 'OBS_VALUE', 'CURRENCY', 'CURRENCY_DENOM')) {
        if (-not ($rows[0].PSObject.Properties.Name -contains $required)) {
            throw "In der EZB-Datei fehlt die Spalte $required."
        }
    }
    $rates = @{}
    foreach ($row in $rows) {
        if ([string] $row.CURRENCY -ne 'USD' -or [string] $row.CURRENCY_DENOM -ne 'EUR') {
            continue
        }
        $date = (Convert-IsoDate ([string] $row.TIME_PERIOD) 'EZB-Datum').ToString('yyyy-MM-dd')
        $rate = Convert-PositiveNumber $row.OBS_VALUE
        if ($null -ne $rate) {
            $rates[$date] = [double] $rate
        }
    }
    if ($rates.Count -eq 0) {
        throw 'Die EZB-Datei enthält keine gültigen USD/EUR-Referenzkurse.'
    }
    return $rates
}

function Build-FxMap([string[]] $MarketDates, [hashtable] $Rates) {
    $rateDates = @($Rates.Keys | Sort-Object)
    $cursor = 0
    $currentDate = $null
    $currentRate = $null
    $result = @{}
    foreach ($marketDate in @($MarketDates | Sort-Object -Unique)) {
        while ($cursor -lt $rateDates.Count -and $rateDates[$cursor] -le $marketDate) {
            $currentDate = $rateDates[$cursor]
            $currentRate = $Rates[$currentDate]
            $cursor++
        }
        if ($null -eq $currentDate -or $null -eq $currentRate) {
            throw "Vor $marketDate fehlt ein verwendbarer EZB-Referenzkurs."
        }
        $result[$marketDate] = [pscustomobject]@{
            Date = $currentDate
            Rate = [double] $currentRate
        }
    }
    return $result
}

function Convert-ToCsvField([object] $Value) {
    $text = [string] $Value
    if ($text -match '[,"\r\n]') {
        return '"' + $text.Replace('"', '""') + '"'
    }
    return $text
}

function Write-PreparedSeries(
    [string] $Path,
    [hashtable] $Values,
    [hashtable] $FxByDate,
    [string] $RevisionPrefix,
    [string] $RawColumnName
) {
    $encoding = [Text.UTF8Encoding]::new($false)
    $writer = [IO.StreamWriter]::new($Path, $false, $encoding)
    $carriedFxDates = 0
    try {
        $writer.WriteLine("date,value,currency,quality_status,source_revision,$RawColumnName,usd_per_eur,fx_observation_date")
        foreach ($date in @($Values.Keys | Sort-Object)) {
            $fx = $FxByDate[$date]
            if ($fx.Date -ne $date) {
                $carriedFxDates++
            }
            $raw = [double] $Values[$date]
            $eur = $raw / [double] $fx.Rate
            $fields = @(
                $date,
                $eur.ToString('F10', $Invariant),
                'EUR',
                'valid',
                "$RevisionPrefix`:$date;ecb-usd-eur:$($fx.Date)",
                $raw.ToString('0.###############', $Invariant),
                ([double] $fx.Rate).ToString('0.####', $Invariant),
                $fx.Date
            ) | ForEach-Object { Convert-ToCsvField $_ }
            $writer.WriteLine(($fields -join ','))
        }
    } finally {
        $writer.Dispose()
    }
    return $carriedFxDates
}

$startDate = Convert-IsoDate $From 'Startdatum'
$endDate = Convert-IsoDate $To 'Enddatum'
if ($startDate -gt $endDate) {
    throw 'Das Startdatum muss vor oder am Enddatum liegen.'
}
if (-not (Test-Path -LiteralPath $Sp500Input -PathType Leaf)) {
    throw "S&P-500-Datei fehlt: $Sp500Input"
}
if (-not (Test-Path -LiteralPath $GoldInput -PathType Leaf)) {
    throw "Gold-Datei fehlt: $GoldInput"
}
if (-not (Test-Path -LiteralPath $OutputDirectory -PathType Container)) {
    New-Item -ItemType Directory -Path $OutputDirectory | Out-Null
}

$temporaryFiles = [Collections.Generic.List[string]]::new()
try {
    if (-not $GoldArchive) {
        $GoldArchive = Join-Path ([IO.Path]::GetTempPath()) ("gld-history-{0}.xlsx" -f [guid]::NewGuid())
        Save-RemoteFile 'https://api.spdrgoldshares.com/api/v1/historical-archive?exchange=NYSE&lang=en&product=gld' $GoldArchive
        $temporaryFiles.Add($GoldArchive)
    }
    if (-not $EcbInput) {
        $EcbInput = Join-Path ([IO.Path]::GetTempPath()) ("ecb-usd-eur-{0}.csv" -f [guid]::NewGuid())
        $fxStart = $startDate.AddDays(-7).ToString('yyyy-MM-dd')
        $fxEnd = $endDate.ToString('yyyy-MM-dd')
        $ecbUrl = "https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A?startPeriod=$fxStart&endPeriod=$fxEnd&format=csvdata"
        Save-RemoteFile $ecbUrl $EcbInput
        $temporaryFiles.Add($EcbInput)
    }
    if (-not (Test-Path -LiteralPath $GoldArchive -PathType Leaf)) {
        throw "Offizielles GLD-Archiv fehlt: $GoldArchive"
    }
    if (-not (Test-Path -LiteralPath $EcbInput -PathType Leaf)) {
        throw "EZB-Wechselkursdatei fehlt: $EcbInput"
    }

    $sp500 = Read-Sp500 $Sp500Input $startDate $endDate
    $gold = Read-OfficialGldArchive $GoldArchive $startDate $endDate
    $goldIdentity = Confirm-GoldIdentity $GoldInput $gold.Values $startDate $endDate
    if ($sp500.Values.Count -eq 0 -or $gold.Values.Count -eq 0) {
        throw 'Mindestens eine Marktdatei enthält im Zielzeitraum keine gültigen Werte.'
    }

    $dateDifference = @(Compare-Object @($sp500.Values.Keys | Sort-Object) @($gold.Values.Keys | Sort-Object))
    if ($dateDifference.Count -gt 0) {
        $preview = ($dateDifference | Select-Object -First 10 | ForEach-Object { $_.InputObject }) -join ', '
        throw "S&P 500 und GLD besitzen unterschiedliche US-Handelstage. Erste Abweichungen: $preview"
    }

    $rates = Read-EcbRates $EcbInput
    $fxByDate = Build-FxMap @($sp500.Values.Keys) $rates
    $sp500Output = Join-Path $OutputDirectory 'sp500.csv'
    $goldOutput = Join-Path $OutputDirectory 'gold.csv'
    $sp500CarriedFx = Write-PreparedSeries $sp500Output $sp500.Values $fxByDate 'fred-sp500' 'sp500_usd_close'
    $goldCarriedFx = Write-PreparedSeries $goldOutput $gold.Values $fxByDate 'spdr-gld-official' 'gld_usd_close'

    $latestInputGold = $goldIdentity.LatestDate
    $supplementedGoldRows = @($gold.Values.Keys | Where-Object { $_ -gt $latestInputGold }).Count
    $firstMarketDate = @($sp500.Values.Keys | Sort-Object | Select-Object -First 1)[0]
    $lastMarketDate = @($sp500.Values.Keys | Sort-Object | Select-Object -Last 1)[0]
    [pscustomobject]@{
        success = $true
        requested_from = $startDate.ToString('yyyy-MM-dd')
        requested_to = $endDate.ToString('yyyy-MM-dd')
        first_market_date = $firstMarketDate
        last_market_date = $lastMarketDate
        sp500_rows_written = $sp500.Values.Count
        sp500_non_observation_placeholders_removed = $sp500.Placeholders
        gold_rows_written = $gold.Values.Count
        gold_input_rows_read = $goldIdentity.Rows
        gold_overlap_rows_verified = $goldIdentity.VerifiedRows
        gold_input_last_date = $latestInputGold
        gold_rows_added_after_input = $supplementedGoldRows
        gold_maximum_input_vs_official_difference_usd = [math]::Round($goldIdentity.MaximumDifferenceUsd, 8)
        sp500_dates_using_previous_ecb_rate = $sp500CarriedFx
        gold_dates_using_previous_ecb_rate = $goldCarriedFx
        sp500_output = $sp500Output
        gold_output = $goldOutput
        sp500_sha256 = (Get-FileHash -LiteralPath $sp500Output -Algorithm SHA256).Hash
        gold_sha256 = (Get-FileHash -LiteralPath $goldOutput -Algorithm SHA256).Hash
    } | ConvertTo-Json -Depth 4
} finally {
    foreach ($file in $temporaryFiles) {
        Remove-Item -LiteralPath $file -Force -ErrorAction SilentlyContinue
    }
}
