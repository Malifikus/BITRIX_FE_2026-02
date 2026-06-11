<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$APPLICATION->SetTitle('Группировка потоков');

use Bitrix\Main\Application;

$connection = Application::getConnection();

// Добавляем колонки если их нет
try {
    $connection->query("ALTER TABLE b_custom_flow_groups ADD COLUMN DESCRIPTION TEXT NULL");
} catch (Exception $e) {}
try {
    $connection->query("ALTER TABLE b_custom_flow_groups ADD COLUMN OWNER_ID INT(11) NULL");
} catch (Exception $e) {}

// Убеждаемся, что таблица существует
$connection->query("CREATE TABLE IF NOT EXISTS b_custom_flow_groups (
    ID INT(11) NOT NULL AUTO_INCREMENT,
    NAME VARCHAR(255) NOT NULL,
    DESCRIPTION TEXT NULL,
    OWNER_ID INT(11) NULL,
    SORT INT(11) DEFAULT 500,
    FLOW_IDS TEXT,
    EXPANDED CHAR(1) DEFAULT 'N',
    ACTIVE CHAR(1) DEFAULT 'Y',
    PRIMARY KEY (ID)
)");

// Получаем список потоков через прямой SQL
$flows = [];
try {
    $res = $connection->query("SELECT ID, NAME FROM b_tasks_flow ORDER BY NAME ASC");
    while ($row = $res->fetch()) {
        $flows[$row['ID']] = $row['NAME'];
    }
} catch (Exception $e) {}

// Обработка сохранения
if ($_SERVER['REQUEST_METHOD'] == 'POST' && check_bitrix_sessid() && isset($_POST['save'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $ownerId = (int)$_POST['owner_id'];
    $sort = (int)$_POST['sort'];
    $flowIds = $_POST['flow_ids'] ? array_map('intval', explode(',', $_POST['flow_ids'])) : [];
    $expanded = $_POST['expanded'] == 'Y' ? 'Y' : 'N';
    $active = $_POST['active'] == 'Y' ? 'Y' : 'N';
    
    $flowIdsStr = $connection->getSqlHelper()->forSql(serialize($flowIds));
    $nameSql = $connection->getSqlHelper()->forSql($name);
    $descriptionSql = $connection->getSqlHelper()->forSql($description);
    
    if ($id > 0) {
        $connection->query("UPDATE b_custom_flow_groups SET 
            NAME = '$nameSql',
            DESCRIPTION = '$descriptionSql',
            OWNER_ID = $ownerId,
            SORT = $sort,
            FLOW_IDS = '$flowIdsStr',
            EXPANDED = '$expanded',
            ACTIVE = '$active'
            WHERE ID = $id");
        CAdminMessage::ShowNote("Группа обновлена");
    } else {
        $connection->query("INSERT INTO b_custom_flow_groups (NAME, DESCRIPTION, OWNER_ID, SORT, FLOW_IDS, EXPANDED, ACTIVE) 
            VALUES ('$nameSql', '$descriptionSql', $ownerId, $sort, '$flowIdsStr', '$expanded', '$active')");
        CAdminMessage::ShowNote("Группа добавлена");
    }
    
    LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID);
}

// Удаление
if (isset($_GET['delete']) && $_GET['delete'] > 0) {
    $connection->query("DELETE FROM b_custom_flow_groups WHERE ID = " . (int)$_GET['delete']);
    LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID);
}

// Получаем список групп
$groups = [];
$res = $connection->query("SELECT * FROM b_custom_flow_groups ORDER BY SORT ASC, ID ASC");
while ($row = $res->fetch()) {
    $row['FLOW_IDS'] = unserialize($row['FLOW_IDS']);
    // Получаем имя пользователя для OWNER_ID
    if ($row['OWNER_ID'] && $row['OWNER_ID'] > 0) {
        $userRes = $connection->query("SELECT NAME, LAST_NAME FROM b_user WHERE ID = " . (int)$row['OWNER_ID']);
        $user = $userRes->fetch();
        $row['OWNER_NAME'] = $user ? trim($user['NAME'] . ' ' . $user['LAST_NAME']) : '';
    }
    $groups[] = $row;
}

$editId = (int)($_GET['edit'] ?? 0);
$editData = null;
if ($editId > 0) {
    $res = $connection->query("SELECT * FROM b_custom_flow_groups WHERE ID = $editId");
    $editData = $res->fetch();
    if ($editData) {
        $editData['FLOW_IDS'] = unserialize($editData['FLOW_IDS']);
    }
}
?>

<style>
    .form-table { margin-bottom: 30px; }
    .form-table td { padding: 10px; vertical-align: top; }
    .form-table td:first-child { width: 200px; font-weight: bold; }
    .groups-table { width: 100%; border-collapse: collapse; }
    .groups-table th, .groups-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    .groups-table th { background: #f5f5f5; }
    .form-row { margin-bottom: 15px; }
    .form-row label { display: inline-block; width: 180px; font-weight: bold; vertical-align: top; }
    .help-text { font-size: 12px; color: #666; margin-left: 185px; margin-top: 5px; }
</style>

<h2><?= $editId ? 'Редактирование группы' : 'Добавление группы' ?></h2>

<form method="post">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="id" value="<?= $editId ?>">
    
    <table class="form-table">
        <tr>
            <td>Название: <span style="color:red">*</span></td>
            <td><input type="text" name="name" value="<?= htmlspecialchars($editData['NAME'] ?? '') ?>" style="width:300px" required></td>
        </tr>
        <tr>
            <td>ID потоков (через запятую):</td>
            <td>
                <input type="text" name="flow_ids" id="flow_ids" value="<?= isset($editData['FLOW_IDS']) && is_array($editData['FLOW_IDS']) ? implode(',', $editData['FLOW_IDS']) : '' ?>" style="width:400px">
                <div style="font-size:12px;color:#666;margin-top:5px;">Пример: 1,5,8,12</div>
                <?php if (!empty($flows)): ?>
                <div style="margin-top:10px;">
                    <select id="quick_select" multiple size="5" style="width:400px">
                        <?php foreach ($flows as $id => $name): ?>
                            <option value="<?= $id ?>">[<?= $id ?>] <?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="addSelected()">Добавить выбранные</button>
                    <button type="button" onclick="clearFlows()">Очистить</button>
                </div>
                <?php else: ?>
                <div style="font-size:12px;color:#999;margin-top:5px;">Список потоков не загружен (таблица b_tasks_flow не найдена)</div>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td>Описание раздела:</td>
            <td><textarea name="description" rows="3" style="width:400px"><?= htmlspecialchars($editData['DESCRIPTION'] ?? '') ?></textarea>
                <div style="font-size:12px;color:#666;margin-top:5px;">Краткое описание раздела, будет отображаться под названием</div>
            </td>
        </tr>
        <tr>
            <td>Владелец раздела (ID пользователя):</td>
            <td><input type="text" name="owner_id" value="<?= htmlspecialchars($editData['OWNER_ID'] ?? '') ?>" style="width:100px">
                <div style="font-size:12px;color:#666;margin-top:5px;">ID пользователя, который будет отображаться как администратор раздела</div>
            </td>
        </tr>
        <tr>
            <td>Сортировка:</td>
            <td><input type="text" name="sort" value="<?= $editData['SORT'] ?? 500 ?>" style="width:100px"></td>
        </tr>
        <tr>
            <td>Раскрыт по умолчанию:</td>
            <td><input type="checkbox" name="expanded" value="Y" <?= ($editData['EXPANDED'] ?? 'N') == 'Y' ? 'checked' : '' ?>></td>
        </tr>
        <tr>
            <td>Активен:</td>
            <td><input type="checkbox" name="active" value="Y" <?= ($editData['ACTIVE'] ?? 'Y') == 'Y' ? 'checked' : '' ?>></td>
        </tr>
    </table>
    
    <input type="submit" name="save" value="Сохранить" class="adm-btn-save">
    <?php if ($editId): ?>
        <a href="flow_groups.php?lang=<?= LANGUAGE_ID ?>">Отмена</a>
    <?php endif; ?>
</form>

<h2>Список групп</h2>

<?php if (empty($groups)): ?>
    <p>Нет созданных групп</p>
<?php else: ?>
    <table class="groups-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Название</th>
                <th>ID потоков</th>
                <th>Описание</th>
                <th>Владелец</th>
                <th>Сорт</th>
                <th>Раскрыт</th>
                <th>Активен</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $g): ?>
            <tr>
                <td><?= $g['ID'] ?></td>
                <td><b><?= htmlspecialchars($g['NAME']) ?></b></td>
                <td><?= is_array($g['FLOW_IDS']) ? implode(', ', $g['FLOW_IDS']) : '-' ?></td>
                <td><?= htmlspecialchars($g['DESCRIPTION'] ?? '-') ?></td>
                <td>
                    <?php if ($g['OWNER_ID'] && $g['OWNER_ID'] > 0): ?>
                        <a href="/company/personal/user/<?= $g['OWNER_ID'] ?>/" target="_blank">
                            <?= htmlspecialchars($g['OWNER_NAME'] ?: 'Пользователь ' . $g['OWNER_ID']) ?>
                        </a>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td><?= $g['SORT'] ?></td>
                <td><?= $g['EXPANDED'] == 'Y' ? 'Да' : 'Нет' ?></td>
                <td><?= $g['ACTIVE'] == 'Y' ? 'Да' : 'Нет' ?></td>
                <td>
                    <a href="?edit=<?= $g['ID'] ?>&lang=<?= LANGUAGE_ID ?>">Ред</a>
                    <a href="?delete=<?= $g['ID'] ?>&lang=<?= LANGUAGE_ID ?>" onclick="return confirm('Удалить?')" style="color:red">Удл</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script>
function addSelected() {
    var select = document.getElementById('quick_select');
    var input = document.getElementById('flow_ids');
    var current = input.value ? input.value.split(',').map(function(v) { return v.trim(); }) : [];
    
    for (var i = 0; i < select.options.length; i++) {
        if (select.options[i].selected) {
            var val = select.options[i].value;
            if (current.indexOf(val) === -1) {
                current.push(val);
            }
        }
    }
    input.value = current.join(',');
}

function clearFlows() {
    document.getElementById('flow_ids').value = '';
}
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
?>