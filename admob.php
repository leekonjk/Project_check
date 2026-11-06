<?php
declare(strict_types=1);

function admobConfigured(): bool
{
    foreach (['ADMOB_PUBLISHER_ID', 'ADMOB_APP_ID', 'ADMOB_CLIENT_ID', 'ADMOB_CLIENT_SECRET', 'ADMOB_REFRESH_TOKEN'] as $key) {
        if (setting($key) === '') {
            return false;
        }
    }
    return true;
}

function googleRequest(string $url, string $body, array $headers): array
{
    if (!function_exists('curl_init')) {
        fail(503, 'Enable the PHP cURL extension to use AdMob reports.');
    }
    $response = '';
    $request = curl_init($url);
    curl_setopt_array($request, [
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 1024 * 1024) {
                return 0;
            }
            $response .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $data = json_decode($response, true);
    if ($ok === false || $status !== 200 || !is_array($data)) {
        error_log('AdMob request failed (upstream HTTP ' . (int)$status . ').');
        fail(502, 'AdMob could not return the report. Check the Google connection and try again.');
    }
    return $data;
}

function reportCounts(array $values): array
{
    $fields = [
        'earnings' => ['ESTIMATED_EARNINGS', 'microsValue'],
        'requests' => ['AD_REQUESTS', 'integerValue'],
        'matched' => ['MATCHED_REQUESTS', 'integerValue'],
        'impressions' => ['IMPRESSIONS', 'integerValue'],
        'clicks' => ['CLICKS', 'integerValue'],
    ];
    $counts = [];
    foreach ($fields as $name => [$metric, $type]) {
        // Google uses strings for int64 JSON values and can omit a zero-value field.
        $value = $values[$metric][$type] ?? 0;
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?[0-9]+$/D', $value))) {
            fail(502, 'AdMob returned an invalid metric.');
        }
        $counts[$name] = (int)$value;
    }
    return $counts;
}

function parseAdmobReport(array $response, string $dimension, string $appId): array
{
    $header = $response[0]['header'] ?? null;
    $footer = $response[count($response) - 1]['footer'] ?? null;
    $currency = $header['localizationSettings']['currencyCode'] ?? '';
    if (!is_array($footer) || !is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) {
        fail(502, 'AdMob returned an incomplete report.');
    }
    $rows = [];
    $totals = reportCounts([]);
    foreach ($response as $item) {
        if (!isset($item['row'])) {
            continue;
        }
        $row = $item['row'];
        $values = $row['dimensionValues'] ?? [];
        if (($values['APP']['value'] ?? '') !== $appId || !isset($values[$dimension]['value'])) {
            fail(502, 'AdMob returned an unexpected report row.');
        }
        $counts = reportCounts($row['metricValues'] ?? []);
        foreach ($counts as $name => $count) {
            $totals[$name] += $count;
        }
        $rows[] = ['label' => $values[$dimension]['displayLabel'] ?? $values[$dimension]['value']] + $counts;
    }
    if ((int)($footer['matchingRowCount'] ?? 0) !== count($rows)) {
        fail(502, 'AdMob returned a partial report. Choose a shorter date range.');
    }
    return ['currency' => $currency, 'rows' => $rows, 'totals' => $totals, 'warnings' => !empty($footer['warnings'])];
}

function admobReport(int $days, string $dimension): array
{
    $publisher = setting('ADMOB_PUBLISHER_ID');
    $appId = setting('ADMOB_APP_ID');
    if (!admobConfigured() || !preg_match('/^pub-[0-9]{16}$/D', $publisher)
        || !preg_match('/^ca-app-pub-[0-9]{16}~[0-9]+$/D', $appId)
        || str_starts_with($appId, 'ca-app-pub-3940256099942544~')) {
        fail(503, 'Configure your AdMob publisher, production app ID and reporting credentials on the server.');
    }

    $token = googleRequest('https://oauth2.googleapis.com/token', http_build_query([
        'client_id' => setting('ADMOB_CLIENT_ID'),
        'client_secret' => setting('ADMOB_CLIENT_SECRET'),
        'refresh_token' => setting('ADMOB_REFRESH_TOKEN'),
        'grant_type' => 'refresh_token',
    ]), ['Content-Type: application/x-www-form-urlencoded']);
    $access = $token['access_token'] ?? '';
    if (!is_string($access) || !preg_match('#^[a-zA-Z0-9._~+/=-]+$#D', $access)) {
        fail(502, 'Google returned an invalid access token.');
    }

    $timezone = 'America/Los_Angeles';
    $end = new DateTimeImmutable('yesterday', new DateTimeZone($timezone));
    $start = $end->modify('-' . ($days - 1) . ' days');
    $date = static fn (DateTimeImmutable $day) => [
        'year' => (int)$day->format('Y'), 'month' => (int)$day->format('n'), 'day' => (int)$day->format('j'),
    ];
    $response = googleRequest(
        "https://admob.googleapis.com/v1/accounts/$publisher/networkReport:generate",
        json_encode(['reportSpec' => [
            'dateRange' => ['startDate' => $date($start), 'endDate' => $date($end)],
            'timeZone' => $timezone,
            'dimensions' => ['APP', $dimension],
            'metrics' => ['ESTIMATED_EARNINGS', 'AD_REQUESTS', 'MATCHED_REQUESTS', 'IMPRESSIONS', 'CLICKS'],
            'dimensionFilters' => [['dimension' => 'APP', 'matchesAny' => ['values' => [$appId]]]],
            'sortConditions' => [$dimension === 'DATE'
                ? ['dimension' => 'DATE', 'order' => 'ASCENDING']
                : ['metric' => 'ESTIMATED_EARNINGS', 'order' => 'DESCENDING']],
            'maxReportRows' => 1000,
        ]], JSON_THROW_ON_ERROR),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $access]
    );

    return parseAdmobReport($response, $dimension, $appId)
        + ['period' => $start->format('Y-m-d') . ' to ' . $end->format('Y-m-d') . ' · Pacific time'];
}
