<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

// ─── Wishlist → Miniature conversion ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['convert_id'])) {
    verify_csrf();
    $id = (int) $_POST['convert_id'];
    $stmt = db()->prepare('SELECT * FROM wishlist WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, current_user_id()]);
    $wish = $stmt->fetch();
    if ($wish) {
        $manufacturer = trim($wish['manufacturer'] ?? '');
        if (!$manufacturer) {
            flash('Preencha o fabricante na wishlist antes de converter para a coleção.', 'warning');
            redirect('/admin/wishlist?edit=' . $id);
        }
        $ins = db()->prepare(
            'INSERT INTO miniatures (name, manufacturer, scale, `condition`, location, private_notes, user_id)
             VALUES (:name, :manufacturer, :scale, :condition, :location, :private_notes, :user_id)'
        );
        $ins->execute([
            'name'          => $wish['name'],
            'manufacturer'  => $manufacturer,
            'scale'         => $wish['scale'],
            'condition'     => 'sealed',
            'location'      => 'storage',
            'private_notes' => $wish['notes'],
            'user_id'       => current_user_id(),
        ]);
        $mini_id = (int) db()->lastInsertId();
        db()->prepare("UPDATE wishlist SET status = 'purchased' WHERE id = ? AND user_id = ?")->execute([$id, current_user_id()]);
        flash('Peça convertida para a coleção! Edite os detalhes agora.');
        redirect('/admin/miniatures?action=edit&id=' . $mini_id);
    }
}

/**
 * Reconstrói a URL da listagem preservando filtros enviados pelos formulários
 * (campos ocultos r_status / r_search / r_sort), para manter busca/ordenação
 * após salvar, excluir ou converter.
 */
function wishlist_back_url(): string {
    $p = [];
    $status = trim($_POST['r_status'] ?? '');
    $search = trim($_POST['r_search'] ?? '');
    $sort   = trim($_POST['r_sort'] ?? '');
    if ($status !== '')                       $p['status'] = $status;
    if ($search !== '')                       $p['search'] = $search;
    if ($sort !== '' && $sort !== 'recent')   $p['sort']   = $sort;
    return '/admin/wishlist.php' . ($p ? '?' . http_build_query($p) : '');
}

// ─── Save ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['convert_id']) && !isset($_POST['delete_id'])) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);

    $data = [
        'name'          => trim($_POST['name'] ?? ''),
        'manufacturer'  => trim($_POST['manufacturer'] ?? '') ?: null,
        'scale'         => trim($_POST['scale'] ?? '') ?: null,
        'target_price'  => strlen(trim($_POST['target_price'] ?? '')) ? (float) str_replace(',', '.', $_POST['target_price']) : null,
        'reference_url' => trim($_POST['reference_url'] ?? '') ?: null,
        'notes'         => trim($_POST['notes'] ?? '') ?: null,
        'status'        => $_POST['status'] ?? 'wanted',
        'user_id'       => current_user_id(),
    ];

    if (!$data['name']) {
        flash('Nome é obrigatório.', 'danger');
        redirect(wishlist_back_url());
    }

    if ($id) {
        // remove user_id from SET (it's in WHERE)
        $update_data = $data;
        unset($update_data['user_id']);
        $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($update_data)));
        $update_data['id']      = $id;
        $update_data['user_id'] = current_user_id();
        db()->prepare("UPDATE wishlist SET $sets WHERE id = :id AND user_id = :user_id")->execute($update_data);
        flash('Wishlist atualizada.');
    } else {
        $cols = implode(', ', array_keys($data));
        $phs  = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
        db()->prepare("INSERT INTO wishlist ($cols) VALUES ($phs)")->execute($data);
        flash('Peça adicionada à wishlist.');
    }

    redirect(wishlist_back_url());
}

// ─── Delete (POST + CSRF) ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verify_csrf();
    db()->prepare('DELETE FROM wishlist WHERE id = ? AND user_id = ?')->execute([(int) $_POST['delete_id'], current_user_id()]);
    flash('Item removido da wishlist.');
    redirect(wishlist_back_url());
}

// ─── Edit ─────────────────────────────────────────────────────────────────────
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM wishlist WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $_GET['edit'], current_user_id()]);
    $editing = $stmt->fetch() ?: null;
}

// ─── Filtros, busca e ordenação (calculados inline) ────────────────────────────
$valid_status = ['wanted', 'purchased', 'cancelled'];
$valid_sort   = ['recent', 'name', 'manufacturer', 'price_desc', 'price_asc'];

$wl_status = in_array($_GET['status'] ?? '', $valid_status, true) ? $_GET['status'] : '';
$wl_search = trim($_GET['search'] ?? '');
$wl_sort   = in_array($_GET['sort'] ?? '', $valid_sort, true) ? $_GET['sort'] : 'recent';

$uid = current_user_id();
$all = get_wishlist('', $uid); // todos os itens do usuário (ordem inicial: created_at DESC)

// Métricas do hero — baseadas apenas em itens desejados (status = wanted)
$wanted_items = array_values(array_filter($all, fn($w) => $w['status'] === 'wanted'));
$stat_total   = count($wanted_items);
$stat_brands  = count(array_unique(array_filter(array_map(fn($w) => trim((string) ($w['manufacturer'] ?? '')), $wanted_items))));
$stat_scales  = count(array_unique(array_filter(array_map(fn($w) => trim((string) ($w['scale'] ?? '')), $wanted_items))));
$stat_invest  = array_sum(array_map(fn($w) => (float) ($w['target_price'] ?? 0), $wanted_items));

// Contagens por status (para as abas)
$count_all       = count($all);
$count_wanted    = $stat_total;
$count_purchased = count(array_filter($all, fn($w) => $w['status'] === 'purchased'));
$count_cancelled = count(array_filter($all, fn($w) => $w['status'] === 'cancelled'));

// Aplicar filtro de status
$items = $all;
if ($wl_status !== '') {
    $items = array_filter($items, fn($w) => $w['status'] === $wl_status);
}
// Aplicar busca (name, manufacturer, scale, notes)
if ($wl_search !== '') {
    $items = array_filter($items, function ($w) use ($wl_search) {
        foreach (['name', 'manufacturer', 'scale', 'notes'] as $f) {
            if (mb_stripos((string) ($w[$f] ?? ''), $wl_search) !== false) {
                return true;
            }
        }
        return false;
    });
}
$items = array_values($items);
// Ordenação
usort($items, function ($a, $b) use ($wl_sort) {
    switch ($wl_sort) {
        case 'name':         return strcasecmp((string) $a['name'], (string) $b['name']);
        case 'manufacturer': return strcasecmp((string) ($a['manufacturer'] ?? ''), (string) ($b['manufacturer'] ?? ''));
        case 'price_desc':   return ((float) ($b['target_price'] ?? 0)) <=> ((float) ($a['target_price'] ?? 0));
        case 'price_asc':    return ((float) ($a['target_price'] ?? 0)) <=> ((float) ($b['target_price'] ?? 0));
        case 'recent':
        default:             return strcmp((string) $b['created_at'], (string) $a['created_at']);
    }
});

// Estado dos painéis colapsáveis
$wl_open_filters = ($wl_search !== '' || $wl_sort !== 'recent' || !empty($_GET['filters']));
$wl_panel_open   = (bool) $editing;
$wl_has_filters  = ($wl_status !== '' || $wl_search !== '' || $wl_sort !== 'recent');

// Querystring atual (status/search/sort) para preservar estado em links/forms
$wl_qs = array_filter([
    'status' => $wl_status,
    'search' => $wl_search,
    'sort'   => $wl_sort !== 'recent' ? $wl_sort : '',
]);
$wl_url = function (array $extra = []) use ($wl_qs) {
    $p = array_filter(array_merge($wl_qs, $extra), fn($v) => $v !== '' && $v !== null);
    return '/admin/wishlist.php' . ($p ? '?' . http_build_query($p) : '');
};
$wl_tab_url = function (string $st) use ($wl_search, $wl_sort) {
    $p = array_filter([
        'status' => $st,
        'search' => $wl_search,
        'sort'   => $wl_sort !== 'recent' ? $wl_sort : '',
    ]);
    return '/admin/wishlist.php' . ($p ? '?' . http_build_query($p) : '');
};

$wl_slug    = current_user_slug();
$page_title = 'Wishlist';

require_once __DIR__ . '/../includes/header_admin.php';
?>

<!-- Hero ──────────────────────────────────────────────────────────────── -->
<section class="dash-hero wishlist-hero">
    <div class="dash-hero-id">
        <div class="dash-hero-avatar wishlist-hero-icon"><i class="fa fa-heart"></i></div>
        <div class="dash-hero-text">
            <div class="lp-eyebrow">Coleção futura</div>
            <h1 class="dash-hero-name">Wishlist</h1>
            <div class="dash-hero-handle">Peças que ainda quero adicionar à minha garagem.</div>
        </div>
    </div>
    <div class="dash-hero-actions">
        <button type="button" class="md-btn md-btn-primary" id="btnWlAdd"><i class="fa fa-plus"></i>Adicionar desejo</button>
        <?php if ($wl_slug): ?>
        <a href="/u/<?= e($wl_slug) ?>" target="_blank" class="md-btn"><i class="fa fa-warehouse"></i>Minha garagem pública</a>
        <?php endif; ?>
    </div>
</section>

<!-- Resumo ────────────────────────────────────────────────────────────── -->
<div class="cp-stats wishlist-stats">
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($stat_total) ?></span>
        <span class="cp-stat-lbl">desejo<?= $stat_total !== 1 ? 's' : '' ?></span>
    </div>
    <?php if ($stat_brands > 0): ?>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($stat_brands) ?></span>
        <span class="cp-stat-lbl">marca<?= $stat_brands !== 1 ? 's' : '' ?> desejada<?= $stat_brands !== 1 ? 's' : '' ?></span>
    </div>
    <?php endif; ?>
    <?php if ($stat_scales > 0): ?>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($stat_scales) ?></span>
        <span class="cp-stat-lbl">escala<?= $stat_scales !== 1 ? 's' : '' ?> desejada<?= $stat_scales !== 1 ? 's' : '' ?></span>
    </div>
    <?php endif; ?>
    <?php if ($stat_invest > 0): ?>
    <div class="cp-stat">
        <span class="cp-stat-num">R$ <?= number_format($stat_invest, 2, ',', '.') ?></span>
        <span class="cp-stat-lbl">investimento desejado</span>
    </div>
    <?php endif; ?>
</div>

<!-- Painel adicionar/editar (colapsável; abre automaticamente ao editar) ── -->
<section class="wishlist-panel<?= $wl_panel_open ? ' is-open' : '' ?>" id="wlPanel">
    <button type="button" class="wishlist-panel-head" id="btnWlPanel" aria-expanded="<?= $wl_panel_open ? 'true' : 'false' ?>" aria-controls="wlPanelBody">
        <span class="wishlist-panel-head-ico"><i class="fa <?= $editing ? 'fa-pen' : 'fa-plus' ?>"></i></span>
        <span class="wishlist-panel-head-text">
            <span class="lp-eyebrow">Wishlist</span>
            <span class="wishlist-panel-title"><?= $editing ? 'Editar desejo' : 'Adicionar desejo' ?></span>
        </span>
        <i class="fa fa-chevron-down wishlist-panel-caret"></i>
    </button>
    <div class="wishlist-panel-body" id="wlPanelBody">
        <form method="post" action="/admin/wishlist.php">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : '' ?>">
            <input type="hidden" name="r_status" value="<?= e($wl_status) ?>">
            <input type="hidden" name="r_search" value="<?= e($wl_search) ?>">
            <input type="hidden" name="r_sort" value="<?= e($wl_sort) ?>">

            <div class="wishlist-form-grid">
                <div class="wishlist-field wishlist-field-wide">
                    <label for="wlName">Nome <span class="wishlist-req">*</span></label>
                    <input type="text" id="wlName" name="name" class="amf-input" required value="<?= $editing ? e($editing['name']) : '' ?>">
                </div>
                <div class="wishlist-field">
                    <label for="wlManufacturer">Fabricante</label>
                    <input type="text" id="wlManufacturer" name="manufacturer" class="amf-input" value="<?= $editing ? e($editing['manufacturer'] ?? '') : '' ?>">
                </div>
                <div class="wishlist-field">
                    <label for="wlScale">Escala</label>
                    <input type="text" id="wlScale" name="scale" class="amf-input" placeholder="1:64" value="<?= $editing ? e($editing['scale'] ?? '') : '' ?>">
                </div>
                <div class="wishlist-field">
                    <label for="wlPrice">Preço desejado (R$)</label>
                    <input type="number" id="wlPrice" name="target_price" step="0.01" min="0" class="amf-input"
                           value="<?= $editing && $editing['target_price'] !== null ? e(number_format((float) $editing['target_price'], 2, '.', '')) : '' ?>">
                </div>
                <div class="wishlist-field wishlist-field-wide">
                    <label for="wlUrl">Link de referência</label>
                    <input type="url" id="wlUrl" name="reference_url" class="amf-input" placeholder="https://..." value="<?= $editing ? e($editing['reference_url'] ?? '') : '' ?>">
                </div>
                <div class="wishlist-field wishlist-field-wide">
                    <label for="wlNotes">Observações</label>
                    <textarea id="wlNotes" name="notes" rows="2" class="amf-textarea"><?= $editing ? e($editing['notes'] ?? '') : '' ?></textarea>
                </div>
            </div>

            <div class="wishlist-field-block">
                <span class="wishlist-block-label">Status</span>
                <div class="wishlist-picks">
                    <?php
                    $cur_status = $editing ? $editing['status'] : 'wanted';
                    $status_opts = [
                        'wanted'    => ['fa-heart', 'Desejada'],
                        'purchased' => ['fa-check', 'Comprada'],
                        'cancelled' => ['fa-ban',   'Cancelada'],
                    ];
                    foreach ($status_opts as $val => $opt): ?>
                        <label class="amf-pick">
                            <input type="radio" name="status" value="<?= $val ?>" <?= $cur_status === $val ? 'checked' : '' ?>>
                            <span class="amf-pick-pill"><i class="fa <?= $opt[0] ?>"></i><?= $opt[1] ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="wishlist-form-foot">
                <button type="submit" class="md-btn md-btn-primary"><i class="fa fa-save"></i><?= $editing ? 'Salvar' : 'Adicionar desejo' ?></button>
                <?php if ($editing): ?>
                    <a href="<?= e($wl_url()) ?>" class="md-btn"><i class="fa fa-xmark"></i>Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</section>

<!-- Abas de status ─────────────────────────────────────────────────────── -->
<div class="wishlist-tabs">
    <a href="<?= e($wl_tab_url('')) ?>" class="wishlist-tab<?= $wl_status === '' ? ' is-active' : '' ?>">Todas <span class="wishlist-tab-count"><?= $count_all ?></span></a>
    <a href="<?= e($wl_tab_url('wanted')) ?>" class="wishlist-tab<?= $wl_status === 'wanted' ? ' is-active' : '' ?>">Desejadas <span class="wishlist-tab-count"><?= $count_wanted ?></span></a>
    <a href="<?= e($wl_tab_url('purchased')) ?>" class="wishlist-tab<?= $wl_status === 'purchased' ? ' is-active' : '' ?>">Compradas <span class="wishlist-tab-count"><?= $count_purchased ?></span></a>
    <a href="<?= e($wl_tab_url('cancelled')) ?>" class="wishlist-tab<?= $wl_status === 'cancelled' ? ' is-active' : '' ?>">Canceladas <span class="wishlist-tab-count"><?= $count_cancelled ?></span></a>
</div>

<!-- Busca e ordenação (colapsável) ─────────────────────────────────────── -->
<div class="admin-miniatures-toolbar">
    <button type="button" class="admin-miniatures-toolbtn<?= $wl_open_filters ? ' is-open' : '' ?>" id="btnWlFilters"
            aria-expanded="<?= $wl_open_filters ? 'true' : 'false' ?>" aria-controls="wlFilters">
        <i class="fa fa-sliders"></i>
        <span>Busca e ordenação</span>
        <i class="fa fa-chevron-down admin-miniatures-caret"></i>
    </button>
    <div class="admin-miniatures-toolbar-spacer">
        <span class="admin-miniatures-count"><?= number_format(count($items)) ?> resultado<?= count($items) !== 1 ? 's' : '' ?></span>
    </div>
</div>

<div id="wlFilters" class="admin-miniatures-filters<?= $wl_open_filters ? ' is-open' : '' ?>">
    <form method="get" action="/admin/wishlist.php" class="admin-miniatures-form">
        <input type="hidden" name="filters" value="1">
        <?php if ($wl_status !== ''): ?><input type="hidden" name="status" value="<?= e($wl_status) ?>"><?php endif; ?>
        <div class="admin-miniatures-search">
            <i class="fa fa-magnifying-glass"></i>
            <input type="search" name="search" placeholder="Buscar por nome, fabricante, escala ou observações..." value="<?= e($wl_search) ?>">
        </div>
        <div class="admin-miniatures-controls">
            <select name="sort" class="admin-miniatures-select">
                <option value="recent" <?= $wl_sort === 'recent' ? 'selected' : '' ?>>Mais recentes</option>
                <option value="name" <?= $wl_sort === 'name' ? 'selected' : '' ?>>Nome A–Z</option>
                <option value="manufacturer" <?= $wl_sort === 'manufacturer' ? 'selected' : '' ?>>Fabricante A–Z</option>
                <option value="price_desc" <?= $wl_sort === 'price_desc' ? 'selected' : '' ?>>Preço alvo (maior)</option>
                <option value="price_asc" <?= $wl_sort === 'price_asc' ? 'selected' : '' ?>>Preço alvo (menor)</option>
            </select>
            <button type="submit" class="md-btn md-btn-primary admin-miniatures-apply"><i class="fa fa-arrow-right"></i><span>Aplicar</span></button>
            <a href="<?= e($wl_status !== '' ? '/admin/wishlist.php?' . http_build_query(['status' => $wl_status]) : '/admin/wishlist.php') ?>" class="md-btn admin-miniatures-clear" title="Limpar busca e ordenação"><i class="fa fa-rotate-left"></i></a>
        </div>
    </form>
</div>

<!-- Grade de desejos ───────────────────────────────────────────────────── -->
<?php if (empty($items)): ?>
    <div class="admin-miniatures-empty">
        <i class="fa fa-heart"></i>
        <p class="admin-miniatures-empty-title"><?= $wl_has_filters ? 'Nenhum desejo encontrado' : 'Sua wishlist está vazia' ?></p>
        <p class="admin-miniatures-empty-sub"><?= $wl_has_filters ? 'Tente ajustar a busca ou os filtros.' : 'Comece adicionando a primeira peça que você quer caçar.' ?></p>
        <?php if ($wl_has_filters): ?>
            <a href="/admin/wishlist.php" class="md-btn"><i class="fa fa-rotate-left"></i>Limpar filtros</a>
        <?php else: ?>
            <button type="button" class="md-btn md-btn-primary" id="btnWlAddEmpty"><i class="fa fa-plus"></i>Adicionar desejo</button>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="wishlist-grid">
        <?php foreach ($items as $w):
            $st = $w['status'];
            $st_class = match ($st) { 'wanted' => 'wishlist-status-wanted', 'purchased' => 'wishlist-status-purchased', default => 'wishlist-status-cancelled' };
            $st_icon  = match ($st) { 'wanted' => 'fa-heart', 'purchased' => 'fa-check', default => 'fa-ban' };
            $edit_url = $wl_url(['edit' => (int) $w['id']]);
        ?>
        <article class="wishlist-card<?= $st === 'cancelled' ? ' is-dim' : '' ?>">
            <div class="wishlist-card-top">
                <span class="wishlist-status <?= $st_class ?>"><i class="fa <?= $st_icon ?>"></i><?= h(wishlist_status_label($st)) ?></span>
            </div>
            <?php if (!empty($w['manufacturer'])): ?>
                <div class="wishlist-card-maker"><?= e($w['manufacturer']) ?></div>
            <?php endif; ?>
            <h3 class="wishlist-card-name"><?= e($w['name']) ?></h3>
            <div class="wishlist-card-meta">
                <?php if (!empty($w['scale'])): ?>
                    <span class="wishlist-card-spec"><i class="fa fa-ruler-combined"></i><?= e($w['scale']) ?></span>
                <?php endif; ?>
                <?php if ($w['target_price'] !== null): ?>
                    <span class="wishlist-card-spec wishlist-card-price"><i class="fa fa-tag"></i>R$ <?= number_format((float) $w['target_price'], 2, ',', '.') ?></span>
                <?php endif; ?>
            </div>
            <?php if (!empty($w['notes'])): ?>
                <p class="wishlist-card-notes"><?= e($w['notes']) ?></p>
            <?php endif; ?>
            <?php if (!empty($w['reference_url'])): ?>
                <a href="<?= e($w['reference_url']) ?>" target="_blank" rel="noopener" class="wishlist-card-link">
                    <i class="fa fa-up-right-from-square"></i>Ver referência
                </a>
            <?php endif; ?>
            <div class="wishlist-card-actions">
                <?php if ($st === 'wanted'): ?>
                    <form method="post" action="/admin/wishlist.php" class="wishlist-card-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="convert_id" value="<?= (int) $w['id'] ?>">
                        <button type="submit" class="wishlist-card-btn is-success" title="Realizar desejo (mover para a coleção)"
                                onclick="return confirm('Marcar como comprada e adicionar à coleção?')">
                            <i class="fa fa-check"></i><span>Realizar</span>
                        </button>
                    </form>
                <?php endif; ?>
                <a href="<?= e($edit_url) ?>" class="wishlist-card-btn" title="Editar"><i class="fa fa-pen"></i><span>Editar</span></a>
                <form method="post" action="/admin/wishlist.php" class="wishlist-card-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="delete_id" value="<?= (int) $w['id'] ?>">
                    <input type="hidden" name="r_status" value="<?= e($wl_status) ?>">
                    <input type="hidden" name="r_search" value="<?= e($wl_search) ?>">
                    <input type="hidden" name="r_sort" value="<?= e($wl_sort) ?>">
                    <button type="submit" class="wishlist-card-btn is-danger" title="Remover"
                            onclick="return confirm('Remover este item da wishlist?')">
                        <i class="fa fa-trash"></i><span>Excluir</span>
                    </button>
                </form>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
(function () {
    // Painel adicionar/editar
    var panel = document.getElementById('wlPanel');
    var panelBtn = document.getElementById('btnWlPanel');
    function togglePanel(force) {
        var open = force !== undefined ? force : !panel.classList.contains('is-open');
        panel.classList.toggle('is-open', open);
        panelBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (panelBtn) panelBtn.addEventListener('click', function () { togglePanel(); });

    function openPanel() {
        togglePanel(true);
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var name = document.getElementById('wlName');
        if (name) name.focus();
    }
    var addBtn = document.getElementById('btnWlAdd');
    if (addBtn) addBtn.addEventListener('click', openPanel);
    var addEmpty = document.getElementById('btnWlAddEmpty');
    if (addEmpty) addEmpty.addEventListener('click', openPanel);

    // Painel de busca/ordenação
    var fBtn = document.getElementById('btnWlFilters');
    var fPanel = document.getElementById('wlFilters');
    if (fBtn && fPanel) {
        fBtn.addEventListener('click', function () {
            var open = fPanel.classList.toggle('is-open');
            fBtn.classList.toggle('is-open', open);
            fBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
