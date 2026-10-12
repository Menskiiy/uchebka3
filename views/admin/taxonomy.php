<div class="admin__cols admin__cols--form">
    <div>
        <div class="card" style="margin-bottom:14px"><div class="card__body">
            <form method="GET" class="row">
                <input type="search" name="q" value="<?= e($term) ?>" class="input" style="flex:1;min-width:200px"
                       placeholder="<?= $kind === 'merchants' ? 'Название или домен — проверьте, нет ли уже такого' : 'Поиск по названию' ?>">
                <button class="btn btn--primary"><?= icon('search') ?> Найти</button>
                <?php if ($term !== ''): ?>
                    <a href="<?= e(url('/admin/' . $routeSlug)) ?>" class="btn">Сбросить</a>
                <?php endif; ?>
            </form>
        </div></div>

    <div class="card"><div class="card__body" style="padding:0">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:52px"></th>
                    <th>Название</th>
                    <?php if ($kind === 'merchants'): ?><th style="width:150px">Домен</th><?php endif; ?>
                    <th style="width:90px">Постов</th>
                    <th style="width:140px"></th>
                </tr>
            </thead>
            <tbody>
            <?php if (! $items): ?>
                <tr><td colspan="5" class="empty">
                    <?= $term !== '' ? 'Ничего не нашлось по запросу «' . e($term) . '».' : 'Пока пусто.' ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td data-label="">
                        <?php if (! empty($item['logo'])): ?>
                            <span class="admin__logo-thumb"><img src="<?= e(url('/storage/' . $item['logo'])) ?>" alt=""></span>
                        <?php elseif (! empty($item['icon'])): ?>
                            <span class="admin__logo-thumb"><img src="<?= e(url('/storage/' . $item['icon'])) ?>" alt=""></span>
                        <?php else: ?>
                            <span class="merchant-chip" style="width:34px;height:34px;font-size:9px;background:<?= e($item['logo_color'] ?? '#c9ced6') ?>">
                                <?= e(mb_substr($item['name'], 0, 4)) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="td--main">
                        <strong><?= e($item['name']) ?></strong>
                        <?php if (! empty($item['description'])): ?>
                            <div class="muted" style="font-size:12px"><?= e(excerpt($item['description'], 70)) ?></div>
                        <?php endif; ?>
                        <?php if (! empty($item['parent_name'])): ?>
                            <div class="muted" style="font-size:12px">в разделе «<?= e($item['parent_name']) ?>»</div>
                        <?php endif; ?>
                    </td>
                    <?php if ($kind === 'merchants'): ?>
                        <td data-label="Домен" class="muted" style="font-size:13px"><?= e($item['domain']) ?></td>
                    <?php endif; ?>
                    <td data-label="Использований" class="muted"><?= (int) $item['usage_count'] ?></td>
                    <td class="td--actions">
                        <div class="row" style="gap:6px">
                            <a href="?edit=<?= (int) $item['id'] ?>" class="btn btn--sm">Изменить</a>
                            <form method="POST" action="<?= e(url('/admin/' . $routeSlug . '/' . (int) $item['id'] . '/udalit')) ?>"
                                  onsubmit="return confirm('Удалить? Публикации останутся, но потеряют привязку.')">
                                <?= csrf_field() ?>
                                <button class="btn btn--sm">Удалить</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
    </div>

    <div class="card admin__sticky"><div class="card__body">
        <h2 class="card__title"><?= $editing ? 'Редактирование' : 'Добавить' ?></h2>

        <form method="POST" action="<?= e(url('/admin/' . $routeSlug . ($editing ? '/' . (int) $editing['id'] : ''))) ?>"
              enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="field">
                <label for="name">Название</label>
                <input type="text" id="name" name="name" class="input" value="<?= e($editing['name'] ?? '') ?>" required>
            </div>

            <?php if ($kind !== 'categories'): ?>
                <div class="field">
                    <label for="description">Описание</label>
                    <textarea id="description" name="description" class="input" rows="3"><?= e($editing['description'] ?? '') ?></textarea>
                </div>
            <?php endif; ?>

            <?php if ($kind === 'categories'): ?>
                <div class="field">
                    <label for="parent_id">Родительская категория</label>
                    <select name="parent_id" id="parent_id" class="input">
                        <option value="">— верхний уровень —</option>
                        <?php foreach ($parents as $p): ?>
                            <?php if (! $editing || (int) $p['id'] !== (int) $editing['id']): ?>
                                <option value="<?= (int) $p['id'] ?>" <?= (int) ($editing['parent_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
                                    <?= e($p['name']) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($kind === 'merchants'): ?>
                <div class="field">
                    <label for="domain">Домен</label>
                    <input type="text" id="domain" name="domain" class="input" value="<?= e($editing['domain'] ?? '') ?>" placeholder="ozon.ru">
                    <div class="hint">По нему публикации автоматически привязываются к магазину.</div>
                </div>
                <div class="field">
                    <label for="logo_color">Цвет плашки</label>
                    <input type="color" id="logo_color" name="logo_color" class="input" style="height:44px;padding:4px"
                           value="<?= e($editing['logo_color'] ?? '#f5661e') ?>">
                </div>
                <label class="check" style="margin-bottom:16px">
                    <input type="checkbox" name="is_top" value="1" <?= ! empty($editing['is_top']) ? 'checked' : '' ?>>
                    Показывать в блоке «Топ магазины»
                </label>

                <div class="field">
                    <label for="affiliate_url">Партнёрская ссылка — шаблон</label>
                    <input type="text" id="affiliate_url" name="affiliate_url" class="input"
                           value="<?= e($editing['affiliate_url'] ?? '') ?>"
                           placeholder="https://ad.admitad.com/g/xxxx/?ulp={urlenc}&amp;subid={subid}">
                    <div class="hint">
                        Куда оборачивать обычную ссылку на товар. <code>{url}</code> — адрес как есть,
                        <code>{urlenc}</code> — он же в кодировке адреса (обычно нужен именно он),
                        <code>{subid}</code> — метка перехода. Оставьте пустым, если ссылки уже партнёрские.
                    </div>
                </div>

                <div class="field">
                    <label for="affiliate_param">Параметр метки</label>
                    <input type="text" id="affiliate_param" name="affiliate_param" class="input"
                           value="<?= e($editing['affiliate_param'] ?? '') ?>" placeholder="subid">
                    <div class="hint">
                        Для случая, когда авторы вставляют уже партнёрские ссылки: к такой ссылке
                        допишется метка с этим именем. У сетей оно разное — subid, sub_id, utm_term.
                        Заполняйте что-то одно: шаблон или параметр.
                    </div>
                </div>
            <?php endif; ?>

            <div class="field">
                <label for="logo">Логотип</label>
                <?php $currentLogo = $editing['logo'] ?? $editing['icon'] ?? null; ?>
                <?php if ($currentLogo): ?>
                    <div class="admin__preview"><img src="<?= e(url('/storage/' . $currentLogo)) ?>" alt=""></div>
                    <label class="check" style="margin-bottom:8px">
                        <input type="checkbox" name="remove_logo" value="1"> Удалить логотип
                    </label>
                <?php endif; ?>
                <input type="file" name="logo" accept="image/*">
                <div class="hint">PNG или SVG-растр, квадратный, до 1 МБ.</div>
            </div>

            <div class="field">
                <label for="position">Порядок</label>
                <input type="number" id="position" name="position" class="input" value="<?= (int) ($editing['position'] ?? 0) ?>">
            </div>

            <button class="btn btn--primary btn--block"><?= $editing ? 'Сохранить' : 'Добавить' ?></button>

            <?php if ($editing): ?>
                <a href="<?= e(url('/admin/' . $routeSlug)) ?>" class="btn btn--block" style="margin-top:8px">Отменить</a>
            <?php endif; ?>
        </form>
    </div></div>
</div>
