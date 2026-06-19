<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

// Fetch all miniatures with category and tags
$stmt = db()->query(
    'SELECT m.*,
            c.name AS category_name
     FROM miniatures m
     LEFT JOIN categories c ON m.category_id = c.id
     ORDER BY m.created_at DESC'
);
$miniatures = $stmt->fetchAll();

// Fetch all tags per miniature in one query (avoids N+1)
$tags_by_miniature = [];
if (!empty($miniatures)) {
    $tag_rows = db()->query(
        'SELECT mt.miniature_id, t.name
         FROM miniature_tags mt
         INNER JOIN tags t ON t.id = mt.tag_id
         ORDER BY t.name ASC'
    )->fetchAll();
    foreach ($tag_rows as $row) {
        $tags_by_miniature[$row['miniature_id']][] = $row['name'];
    }
}

// Stream CSV
$filename = APP_NAME . '_colecao_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');

// BOM for Excel UTF-8 compatibility
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

fputcsv($out, [
    'ID',
    'Nome',
    'Fabricante',
    'Modelo',
    'Escala',
    'Ano',
    'Categoria',
    'Status',
    'Preço Pago (R$)',
    'Valor Estimado (R$)',
    'Data Compra',
    'Local Compra',
    'Avaliação Emocional',
    'Tags',
    'Descrição Pública',
    'Data Cadastro',
], separator: ';');

foreach ($miniatures as $m) {
    fputcsv($out, [
        $m['id'],
        $m['name'],
        $m['manufacturer'],
        $m['model'] ?? '',
        $m['scale'] ?? '',
        $m['year'] ?? '',
        $m['category_name'] ?? '',
        condition_label($m['condition'] ?? 'sealed') . ' / ' . location_label($m['location'] ?? 'storage'),
        $m['purchase_price'] !== null ? number_format((float) $m['purchase_price'], 2, ',', '.') : '',
        $m['estimated_price'] !== null ? number_format((float) $m['estimated_price'], 2, ',', '.') : '',
        $m['purchase_date'] ?? '',
        $m['purchase_location'] ?? '',
        $m['emotional_rating'] ? emotional_rating_label((int) $m['emotional_rating']) : '',
        implode(', ', $tags_by_miniature[$m['id']] ?? []),
        $m['public_description'] ?? '',
        $m['created_at'],
    ], separator: ';');
}

fclose($out);
exit;
