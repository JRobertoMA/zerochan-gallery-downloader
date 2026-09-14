<?php
/**
 * GET api/downloads.php?tag=X&q=texto&sort=date|size|id&p=1&l=60
 * → { items, count, page, limit, tags: [{tag,n}], stats: {count, bytes} }   (desde el índice SQLite)
 */
require __DIR__ . '/_bootstrap.php';

$tag = trim((string) ($_GET['tag'] ?? ''));
$res = $index->list([
    'tag'  => $tag,
    'q'    => trim((string) ($_GET['q'] ?? '')),
    'sort' => in_array($_GET['sort'] ?? '', Index::SORTS, true) ? $_GET['sort'] : 'date',
    'p'    => $_GET['p'] ?? 1,
    'l'    => $_GET['l'] ?? 60,
]);
foreach ($res['items'] as &$it) {
    $it['crops'] = Library::cropCount($it['folder'], (int) $it['id']);   // recortes en downloads/<folder>/crops/
}
unset($it);

json_out($res + [
    'tags'  => $index->facetTags(30, $tag !== '' ? $tag : null),
    'stats' => $index->stats(),
]);
