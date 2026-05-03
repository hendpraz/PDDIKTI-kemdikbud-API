<?php

declare(strict_types=1);

const DIKTI_URL = 'aHR0cHM6Ly9hcGktcGRkaWt0aS5rZW1kaWt0aXNhaW50ZWsuZ28uaWQ=';
const HOST = 'YXBpLXBkZGlrdGkua2VtZGlrdGlzYWludGVrLmdvLmlk';
const ORIGIN = 'aHR0cHM6Ly9wZGRpa3RpLmtlbWRpa3Rpc2FpbnRlay5nby5pZA==';
const REFERER = 'aHR0cHM6Ly9wZGRpa3RpLmtlbWRpa3Rpc2FpbnRlay5nby5pZC8=';
const FALLBACK_IP = 'MTAzLjQ3LjEzMi4yOQ==';

const DEFAULT_QUERY_FILE = __DIR__ . '/all_mahasiswa_itb.php';
const DEFAULT_OUTPUT_FILE = __DIR__ . '/mahasiswa_itb.txt';

function decode_base64_value(string $value): string
{
    return base64_decode($value, true) ?: '';
}

function endpoint(): string
{
    return decode_base64_value(DIKTI_URL);
}

function parse_url_segment(string|int $value): string
{
    return str_replace('%2F', '/', rawurlencode((string) $value));
}

function error_message(Throwable $error): string
{
    return $error->getMessage();
}

function get_ip(): string
{
    try {
        $body = http_get_text('https://api.ipify.org?format=json');
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) && isset($data['ip'])
            ? (string) $data['ip']
            : decode_base64_value(FALLBACK_IP);
    } catch (Throwable $error) {
        fwrite(STDERR, 'Failed to load IP address, using fallback: ' . error_message($error) . PHP_EOL);

        return decode_base64_value(FALLBACK_IP);
    }
}

/**
 * @return array<string, string>
 */
function build_headers(?string $ip = null): array
{
    $userIp = $ip ?? get_ip();

    return [
        'Accept' => 'application/json, text/plain, */*',
        'Accept-Encoding' => 'gzip, deflate, br, zstd',
        'Accept-Language' => 'en-US,en;q=0.9,mt;q=0.8',
        'Connection' => 'keep-alive',
        'DNT' => '1',
        'Host' => decode_base64_value(HOST),
        'Origin' => decode_base64_value(ORIGIN),
        'Referer' => decode_base64_value(REFERER),
        'Sec-Fetch-Dest' => 'empty',
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Site' => 'same-site',
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
        'X-User-IP' => $userIp,
        'sec-ch-ua' => '"Microsoft Edge";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
        'sec-ch-ua-mobile' => '?0',
        'sec-ch-ua-platform' => '"Windows"',
    ];
}

/**
 * @param array<string, string> $headers
 * @return mixed|null
 */
function request_json(string $url, array $headers): mixed
{
    $body = http_get_text($url, $headers);

    return $body !== '' ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : null;
}

/**
 * @param array<string, string> $headers
 * @return array<int, array<string, mixed>>|null
 */
function search_mahasiswa(string $searchQuery, array $headers): ?array
{
    $searchUrl = endpoint() . '/pencarian/mhs/' . parse_url_segment($searchQuery);
    $data = request_json($searchUrl, $headers);

    return is_array($data) ? $data : null;
}

/**
 * @param array<string, string> $headers
 * @return mixed|null
 */
function get_mhs_detail(string $mahasiswaId, array $headers): mixed
{
    $detailUrl = endpoint() . '/detail/mhs/' . parse_url_segment($mahasiswaId);

    return request_json($detailUrl, $headers);
}

/**
 * @return array<int, string>
 */
function load_queries(string $queryFile = DEFAULT_QUERY_FILE): array
{
    $resolvedQueryFile = realpath($queryFile) ?: $queryFile;
    $queries = require $resolvedQueryFile;

    if (!is_array($queries)) {
        throw new TypeError($resolvedQueryFile . ' must return an array of query strings');
    }

    return array_map(static fn (mixed $query): string => (string) $query, $queries);
}

function append_json_line(string $filePath, mixed $data): void
{
    $directory = dirname($filePath);
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents(
        $filePath,
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX,
    );
}

/**
 * @param array{
 *     queryFile?: string,
 *     outputFile?: string,
 *     delayMs?: int|float,
 *     limit?: int|null,
 *     headers?: array<string, string>,
 *     onDetail?: callable(mixed, array<string, mixed>, string): void
 * } $options
 * @return array{queryCount: int, detailCount: int, outputFile: string}
 */
function scrape_mahasiswa_details(array $options = []): array
{
    $queryFile = $options['queryFile'] ?? DEFAULT_QUERY_FILE;
    $outputFile = $options['outputFile'] ?? DEFAULT_OUTPUT_FILE;
    $delayMs = (float) ($options['delayMs'] ?? 20);
    $limit = $options['limit'] ?? null;
    $headers = $options['headers'] ?? build_headers();
    $onDetail = $options['onDetail'] ?? static function (mixed $detail, array $mahasiswa, string $query) use ($outputFile): void {
        append_json_line($outputFile, $detail);
    };

    $queries = load_queries($queryFile);
    $selectedQueries = is_int($limit) ? array_slice($queries, 0, $limit) : $queries;
    $queryCount = 0;
    $detailCount = 0;

    foreach ($selectedQueries as $query) {
        $queryCount++;

        try {
            $mahasiswaData = search_mahasiswa($query, $headers);
            if (is_array($mahasiswaData)) {
                foreach ($mahasiswaData as $mahasiswa) {
                    if (!is_array($mahasiswa) || empty($mahasiswa['id'])) {
                        continue;
                    }

                    $detail = get_mhs_detail((string) $mahasiswa['id'], $headers);
                    $detailCount++;
                    $onDetail($detail, $mahasiswa, $query);
                }
            }
        } catch (Throwable $error) {
            fwrite(STDERR, 'Error while processing "' . $query . '": ' . error_message($error) . PHP_EOL);
        }

        if ($delayMs > 0) {
            usleep((int) ($delayMs * 1000));
        }
    }

    return [
        'queryCount' => $queryCount,
        'detailCount' => $detailCount,
        'outputFile' => $outputFile,
    ];
}

/**
 * @param array<string, string> $headers
 */
function http_get_text(string $url, array $headers = []): string
{
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException($error !== '' ? $error : 'Request failed');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('Request failed with HTTP ' . $statusCode . ': ' . $url);
        }

        return (string) $body;
    }

    $context = stream_context_create([
        'http' => [
            'header' => implode("\r\n", $headerLines),
            'ignore_errors' => true,
            'method' => 'GET',
            'timeout' => 30,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    $statusCode = http_response_status_code($http_response_header ?? []);

    if ($body === false) {
        throw new RuntimeException('Request failed: ' . $url);
    }

    if ($statusCode !== null && ($statusCode < 200 || $statusCode >= 300)) {
        throw new RuntimeException('Request failed with HTTP ' . $statusCode . ': ' . $url);
    }

    return $body;
}

/**
 * @param array<int, string> $headers
 */
function http_response_status_code(array $headers): ?int
{
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
            return (int) $matches[1];
        }
    }

    return null;
}

/**
 * @param array<int, string> $argv
 * @return array{queryFile: string, outputFile: string, delayMs: int, limit?: int, help?: bool}
 */
function parse_args(array $argv): array
{
    $args = [
        'queryFile' => DEFAULT_QUERY_FILE,
        'outputFile' => DEFAULT_OUTPUT_FILE,
        'delayMs' => 20,
    ];

    for ($index = 0; $index < count($argv); $index++) {
        $arg = $argv[$index];
        $next = $argv[$index + 1] ?? null;

        if ($arg === '--query-file' && $next !== null) {
            $args['queryFile'] = realpath($next) ?: $next;
            $index++;
        } elseif ($arg === '--output' && $next !== null) {
            $args['outputFile'] = $next;
            $index++;
        } elseif ($arg === '--delay' && $next !== null) {
            $args['delayMs'] = (int) $next;
            $index++;
        } elseif ($arg === '--limit' && $next !== null) {
            $args['limit'] = (int) $next;
            $index++;
        } elseif ($arg === '--help') {
            $args['help'] = true;
        }
    }

    return $args;
}

function print_help(): void
{
    echo <<<HELP
Usage: php pddiktiphp/dikti_scraper.php [options]

Options:
  --query-file <path>  PHP file returning an array of query strings
  --output <path>      Output file for JSON lines
  --delay <ms>         Delay between search queries (default: 20)
  --limit <number>     Process only the first N queries
  --help               Show this help message

HELP;
}

function main(array $argv): void
{
    $args = parse_args(array_slice($argv, 1));
    if (($args['help'] ?? false) === true) {
        print_help();
        return;
    }

    $result = scrape_mahasiswa_details($args);
    echo 'Done. Processed ' . $result['queryCount'] . ' queries and wrote ' . $result['detailCount'] . ' details to ' . $result['outputFile'] . PHP_EOL;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    main($argv);
}
