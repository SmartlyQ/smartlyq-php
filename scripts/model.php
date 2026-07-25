<?php

/**
 * Shared spec model for the generator scripts: parses openapi.json into
 * resource groups with the final SDK method names and signatures.
 *
 * Ported 1:1 from the Node SDK generator so both SDKs expose an identical
 * method surface for every operation.
 */

declare(strict_types=1);

namespace Smartlyq\Generator;

const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

/** Tag -> client property. New/unknown tags fall back to auto-camelCase. */
const TAG_KEYS = [
    'Articles' => 'articles',
    'Images' => 'images',
    'Videos' => 'videos',
    'Social' => 'social',
    'Content' => 'content',
    'SEO' => 'seo',
    'Audio' => 'audio',
    'URLs' => 'urls',
    'AI Captain' => 'captain',
    'Chatbot' => 'chatbots',
    'Media' => 'media',
    'Analytics' => 'analytics',
    'Jobs' => 'jobs',
    'Account' => 'account',
    'Comments' => 'comments',
    'Direct Messages' => 'messages',
    'Webhooks' => 'webhooks',
    'Shorts' => 'shorts',
    'Presentations' => 'presentations',
    'CRM Contacts' => 'contacts',
    'CRM Opportunities' => 'opportunities',
    'Workspaces' => 'workspaces',
    'CRM Custom Fields' => 'customFields',
    'Profiles' => 'profiles',
];

/** Extra noise words stripped from method names, per tag. */
const EXTRA_STOPWORDS = [
    'AI Captain' => ['ai'],
    'Direct Messages' => ['direct'],
    'CRM Contacts' => ['crm'],
    'CRM Opportunities' => ['crm'],
    'CRM Custom Fields' => ['crm'],
];

/** @return list<string> */
function camelTokens(string $id): array
{
    preg_match_all('/[A-Z]?[a-z0-9]+|[A-Z]+(?![a-z])/', $id, $matches);

    return $matches[0] !== [] ? $matches[0] : [$id];
}

/** @param list<string> $tokens */
function toCamel(array $tokens): string
{
    $out = '';
    foreach (array_values($tokens) as $i => $token) {
        $out .= $i === 0
            ? strtolower($token[0]) . substr($token, 1)
            : strtoupper($token[0]) . substr($token, 1);
    }

    return $out;
}

function pascal(string $id): string
{
    return strtoupper($id[0]) . substr($id, 1);
}

function snakeToCamel(string $name): string
{
    return preg_replace_callback('/_([a-z0-9])/', static fn (array $m): string => strtoupper($m[1]), $name);
}

/** @return array<string, true> */
function stopwordsFor(string $tag): array
{
    $words = array_map('strtolower', preg_split('/\s+/', $tag));
    foreach (EXTRA_STOPWORDS[$tag] ?? [] as $extra) {
        $words[] = strtolower($extra);
    }

    $set = [];
    foreach ($words as $w) {
        $set[$w] = true;
        if (str_ends_with($w, 'ies')) {
            $set[substr($w, 0, -3) . 'y'] = true;
        } elseif (str_ends_with($w, 's')) {
            $set[substr($w, 0, -1)] = true;
        } else {
            $set[$w . 's'] = true;
            if (str_ends_with($w, 'y')) {
                $set[substr($w, 0, -1) . 'ies'] = true;
            }
        }
    }

    return $set;
}

function methodName(string $tag, string $operationId): string
{
    $stop = stopwordsFor($tag);
    $kept = array_values(array_filter(
        camelTokens($operationId),
        static fn (string $t): bool => !isset($stop[strtolower($t)]),
    ));
    if ($kept === []) {
        return $operationId;
    }

    return toCamel($kept);
}

/**
 * @return list<array{
 *     tag: string,
 *     key: string,
 *     className: string,
 *     methods: list<array{
 *         name: string,
 *         operationId: string,
 *         httpMethod: string,
 *         path: string,
 *         summary: string,
 *         pathParams: list<array{arg: string, raw: string}>,
 *         hasBody: bool,
 *         bodyRequired: bool,
 *         hasQuery: bool
 *     }>
 * }>
 */
function buildModel(?string $specPath = null): array
{
    $specPath ??= dirname(__DIR__) . '/openapi.json';
    $spec = json_decode((string) file_get_contents($specPath), true, 512, JSON_THROW_ON_ERROR);

    $resolveParam = static function (array $param) use ($spec): array {
        if (isset($param['$ref']) && is_string($param['$ref'])) {
            $name = basename($param['$ref']);

            return $spec['components']['parameters'][$name];
        }

        return $param;
    };

    $byTag = [];

    foreach ($spec['paths'] as $path => $methods) {
        foreach (HTTP_METHODS as $httpMethod) {
            $op = $methods[$httpMethod] ?? null;
            if ($op === null) {
                continue;
            }
            $tag = $op['tags'][0] ?? 'Other';
            $params = array_map($resolveParam, $op['parameters'] ?? []);
            $pathParams = [];
            $hasQuery = false;
            foreach ($params as $p) {
                if (($p['in'] ?? '') === 'path') {
                    $pathParams[] = ['arg' => snakeToCamel($p['name']), 'raw' => $p['name']];
                } elseif (($p['in'] ?? '') === 'query') {
                    $hasQuery = true;
                }
            }
            $byTag[$tag][] = [
                'name' => methodName($tag, $op['operationId']),
                'operationId' => $op['operationId'],
                'httpMethod' => strtoupper($httpMethod),
                'path' => $path,
                'summary' => $op['summary'] ?? strtoupper($httpMethod) . ' ' . $path,
                'pathParams' => $pathParams,
                'hasBody' => array_key_exists('requestBody', $op),
                'bodyRequired' => ($op['requestBody']['required'] ?? true) !== false,
                'hasQuery' => $hasQuery,
            ];
        }
    }

    // Collision guard: if two ops in a resource shorten to the same name, keep operationIds.
    foreach ($byTag as $tag => $methods) {
        $counts = [];
        foreach ($methods as $m) {
            $counts[$m['name']] = ($counts[$m['name']] ?? 0) + 1;
        }
        foreach ($methods as $i => $m) {
            if ($counts[$m['name']] > 1) {
                $byTag[$tag][$i]['name'] = $m['operationId'];
            }
        }
    }

    $tags = array_keys($byTag);
    usort($tags, static fn (string $a, string $b): int => strcasecmp($a, $b) ?: strcmp($a, $b));

    $resources = [];
    foreach ($tags as $tag) {
        $key = TAG_KEYS[$tag] ?? toCamel(camelTokens(str_replace(' ', '', $tag)));
        $resources[] = [
            'tag' => $tag,
            'key' => $key,
            'className' => pascal($key) . 'Resource',
            'methods' => $byTag[$tag],
        ];
    }

    return $resources;
}
