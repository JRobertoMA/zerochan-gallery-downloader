<?php
/**
 * GET api/search.php?tags=Tag1,Tag2&p=1&l=40&s=fav&t=1&d=portrait&c=blue&strict=1&ecchi=hide&min=1080
 * → { items: [...+saved,dup_of], raw_count, ecchi, min, page, limit, tags, url }
 */
require __DIR__ . '/_bootstrap.php';

$tags   = parse_tags((string) ($_GET['tags'] ?? ''));
$params = search_params($_GET);

try {
    $raw   = $client->search($tags, $params);
    $items = ZerochanClient::applyLocalFilters($raw, $params);

    // Anota con el índice local: ya descargada (por id) o misma imagen bajo otro id (por md5)
    $saved  = array_flip($index->savedIds(array_column($items, 'id')));
    $owners = $index->md5Owners(array_column($items, 'md5'));
    foreach ($items as &$it) {
        $it['saved']  = isset($saved[$it['id']]);
        $owner = $owners[$it['md5'] ?? ''] ?? null;
        $it['dup_of'] = ($owner !== null && $owner !== (int) $it['id']) ? $owner : null;
    }
    unset($it);

    if (random_int(1, 50) === 1) {
        ZerochanClient::pruneCache();
    }

    header('X-Cache: ' . ($client->lastFromCache ? 'HIT' : 'MISS'));
    json_out([
        'items'     => $items,
        'raw_count' => count($raw),     // antes de filtros locales; el front lo usa para paginar
        'ecchi'     => $params['ecchi'],
        'min'       => $params['min'],
        'page'      => $params['p'],
        'limit'     => $params['l'],
        'tags'      => $tags,
        'url'       => $client->buildSearchUrl($tags, $params),
    ]);
} catch (Throwable $e) {
    json_error($e);
}
