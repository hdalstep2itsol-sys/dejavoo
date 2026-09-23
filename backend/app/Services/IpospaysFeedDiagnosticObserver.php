<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\Str;
use JsonException;
use stdClass;
use Throwable;

class IpospaysFeedDiagnosticObserver
{
    private const MAX_HEADERS = 64;

    private const MAX_DISCOVERED_KEYS = 100;

    private const MAX_DEPTH = 3;

    private const MAX_ARRAY_ITEMS_PER_LEVEL = 10;

    private const MAX_KEY_LENGTH = 128;

    private const MAX_USER_AGENT_LENGTH = 256;

    private const MAX_CONTENT_TYPE_LENGTH = 128;

    public function __construct(private readonly LogManager $logs) {}

    public function observe(Request $request, string $rawBody): ?string
    {
        if (! config('ipospays.feed.diagnostic_mode', false)) {
            return null;
        }

        try {
            $observationId = (string) Str::uuid();
            $this->logs->channel('ipospays_feed')->info(
                'iPOSpays FEED diagnostic request observed.',
                $this->metadata($request, $rawBody, $observationId),
            );

            return $observationId;
        } catch (Throwable) {
            // Diagnostics must never interfere with the fail-closed webhook flow.
            return null;
        }
    }

    public function recordOutcome(?string $observationId, string $outcome): void
    {
        if ($observationId === null || ! config('ipospays.feed.diagnostic_mode', false)) {
            return;
        }

        try {
            $this->logs->channel('ipospays_feed')->info(
                'iPOSpays FEED diagnostic outcome recorded.',
                [
                    'observation_id' => $observationId,
                    'recorded_at' => now()->toIso8601String(),
                    'outcome' => $outcome,
                ],
            );
        } catch (Throwable) {
            // Diagnostics must never interfere with the fail-closed webhook flow.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Request $request, string $rawBody, string $observationId): array
    {
        $headerNames = array_map(
            fn (string $name): string => $this->safeName($name),
            array_keys($request->headers->all()),
        );
        sort($headerNames, SORT_STRING);
        $headersTruncated = count($headerNames) > self::MAX_HEADERS;
        $headerNames = array_slice($headerNames, 0, self::MAX_HEADERS);

        $structure = $this->jsonStructure($rawBody);

        return [
            'observation_id' => $observationId,
            'received_at' => now()->toIso8601String(),
            'outcome' => 'received',
            'http_method' => $request->getMethod(),
            'path' => '/'.ltrim($request->getPathInfo(), '/'),
            'content_type' => $this->safeText(
                $request->headers->get('Content-Type'),
                self::MAX_CONTENT_TYPE_LENGTH,
            ),
            'content_length' => strlen($rawBody),
            'user_agent' => $this->safeText(
                $request->userAgent(),
                self::MAX_USER_AGENT_LENGTH,
            ),
            'header_names' => $headerNames,
            'headers_truncated' => $headersTruncated,
            ...$structure,
        ];
    }

    /**
     * @return array{
     *     json_parse_succeeded: bool|null,
     *     json_inspection: string,
     *     top_level_fields: array<int, string>,
     *     key_paths: array<int, string>,
     *     keys_truncated: bool,
     *     signature_present: bool
     * }
     */
    private function jsonStructure(string $rawBody): array
    {
        $maximumBytes = max(1, (int) config('ipospays.feed.max_payload_bytes', 262144));

        if (strlen($rawBody) > $maximumBytes) {
            return $this->emptyStructure(null, 'skipped_payload_too_large');
        }

        try {
            $decoded = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->emptyStructure(false, 'malformed_json');
        }

        $topLevelFields = [];
        $signaturePresent = false;
        if ($decoded instanceof stdClass) {
            $topLevelFields = array_map(
                fn (string $name): string => $this->safeName($name),
                array_keys(get_object_vars($decoded)),
            );
            $signaturePresent = property_exists($decoded, 'signature');
        }

        $paths = [];
        $truncated = false;
        $this->discoverPaths($decoded, '', 0, $paths, $truncated);

        return [
            'json_parse_succeeded' => true,
            'json_inspection' => 'completed',
            'top_level_fields' => array_slice(
                $topLevelFields,
                0,
                self::MAX_DISCOVERED_KEYS,
            ),
            'key_paths' => array_keys($paths),
            'keys_truncated' => $truncated
                || count($topLevelFields) > self::MAX_DISCOVERED_KEYS,
            'signature_present' => $signaturePresent,
        ];
    }

    /**
     * @param  array<string, true>  $paths
     */
    private function discoverPaths(
        mixed $value,
        string $parentPath,
        int $depth,
        array &$paths,
        bool &$truncated,
    ): void {
        if ($depth >= self::MAX_DEPTH || count($paths) >= self::MAX_DISCOVERED_KEYS) {
            if ((is_array($value) && $value !== [])
                || ($value instanceof stdClass && get_object_vars($value) !== [])) {
                $truncated = true;
            }

            return;
        }

        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $name => $child) {
                if (count($paths) >= self::MAX_DISCOVERED_KEYS) {
                    $truncated = true;

                    return;
                }

                $name = $this->safeName((string) $name);
                $path = $parentPath === '' ? $name : $parentPath.'.'.$name;
                $paths[$path] = true;
                $this->discoverPaths($child, $path, $depth + 1, $paths, $truncated);
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        $arrayPath = $parentPath === '' ? '[]' : $parentPath.'[]';
        foreach (array_slice($value, 0, self::MAX_ARRAY_ITEMS_PER_LEVEL) as $child) {
            $this->discoverPaths($child, $arrayPath, $depth, $paths, $truncated);
        }

        if (count($value) > self::MAX_ARRAY_ITEMS_PER_LEVEL) {
            $truncated = true;
        }
    }

    /**
     * @return array{
     *     json_parse_succeeded: bool|null,
     *     json_inspection: string,
     *     top_level_fields: array<int, string>,
     *     key_paths: array<int, string>,
     *     keys_truncated: bool,
     *     signature_present: bool
     * }
     */
    private function emptyStructure(?bool $parseSucceeded, string $inspection): array
    {
        return [
            'json_parse_succeeded' => $parseSucceeded,
            'json_inspection' => $inspection,
            'top_level_fields' => [],
            'key_paths' => [],
            'keys_truncated' => false,
            'signature_present' => false,
        ];
    }

    private function safeName(string $name): string
    {
        return $this->safeText($name, self::MAX_KEY_LENGTH) ?? '';
    }

    private function safeText(?string $text, int $maximumLength): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';

        return mb_substr($text, 0, $maximumLength);
    }
}
