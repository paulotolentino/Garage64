<?php
// View analytics report. Expects $va (from get_view_analytics()) and $va_days.
$va_groups = [
    ['Origem', 'fa-route', $va['sources'] ?? []],
    ['Países', 'fa-earth-americas', $va['countries'] ?? []],
    ['Cidades', 'fa-location-dot', $va['cities'] ?? []],
];
?>
<div class="va">
    <div class="va-total">
        <strong><?= number_format((int) $va['total'], 0, ',', '.') ?></strong>
        <span><?= (int) $va['total'] === 1 ? 'visualização' : 'visualizações' ?> nos últimos <?= (int) $va_days ?> dias</span>
    </div>
    <?php if ((int) $va['total'] === 0): ?>
        <p class="va-empty">Ainda sem visualizações registradas neste período. Compartilhe suas miniaturas para começar a ver de onde vêm os visitantes.</p>
    <?php else: ?>
    <div class="va-grid">
        <?php foreach ($va_groups as [$title, $icon, $rows]): ?>
        <div class="va-col">
            <div class="va-col-title"><i class="fa <?= $icon ?>"></i><?= e($title) ?></div>
            <?php if (!$rows): ?>
                <div class="va-row-empty">Sem dados</div>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <div class="va-row" title="<?= (int) $r['count'] ?> · <?= (int) $r['pct'] ?>%">
                <div class="va-row-head">
                    <span class="va-row-label">
                        <?php if (isset($r['icon'])): ?><i class="<?= e($r['icon']) ?>"></i><?php else: ?><span class="va-flag"><?= $r['flag'] ?></span><?php endif; ?>
                        <?= e($r['label']) ?>
                    </span>
                    <span class="va-row-count"><?= (int) $r['count'] ?></span>
                </div>
                <div class="va-bar"><span style="width: <?= max(2, (int) $r['pct']) ?>%"></span></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <p class="va-note"><i class="fa fa-circle-info"></i> Não contam: suas próprias visitas, robôs e recarregamentos do mesmo visitante em até <?= (int) VIEW_DEDUPE_HOURS ?>h.</p>
</div>
