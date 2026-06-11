(function() {
    console.log('=== flowgroups.js старт ===');
    
    let checkAttempts = 0;
    const maxChecks = 50;
    
    function waitForData() {
        checkAttempts++;
        
        if (window.FlowGroupsData) {
            console.log('Данные получены:', window.FlowGroupsData);
            startGrouping();
        } else if (checkAttempts < maxChecks) {
            console.log('Ждём данные, попытка ' + checkAttempts);
            setTimeout(waitForData, 100);
        } else {
            console.error('Данные не получены после ' + maxChecks + ' попыток');
        }
    }
    
    function startGrouping() {
        const GROUPS_CONFIG = window.FlowGroupsData;
        let isGroupingApplied = false;
        let isTableShown = false;
        
        // Добавляем класс скрытия таблице, если она уже есть
        function addLoadingClass() {
            const table = document.querySelector('.main-grid-table');
            if (table && !table.classList.contains('custom-flowgroups-loading')) {
                table.classList.add('custom-flowgroups-loading');
            }
        }
        addLoadingClass();
        // Повторяем попытку добавить класс, если таблица появится позже
        setTimeout(addLoadingClass, 500);
        
        function getFlowIdFromRow(row) {
            const dataId = row.getAttribute('data-id');
            return dataId && !isNaN(parseInt(dataId)) ? parseInt(dataId) : null;
        }
        
        function getActivityDate(row) {
            const cell = row.querySelector('td[data-column-id="ACTIVITY"]');
            if (!cell) {
                console.log('getActivityDate: ячейка ACTIVITY не найдена');
                return null;
            }
            const content = cell.querySelector('.main-grid-cell-content');
            if (!content) {
                console.log('getActivityDate: .main-grid-cell-content не найден');
                return null;
            }
            const text = content.textContent.trim();
            console.log(`getActivityDate: найдена активность: "${text}"`);
            return text;
        }
        
        function getEfficiency(row) {
            const cell = row.querySelector('td[data-column-id="EFFICIENCY"]');
            if (!cell) return null;
            
            // Пробуем найти span с процентом
            const span = cell.querySelector('.tasks-flow__list-members_info.--link.--efficiency span');
            if (span) {
                const match = span.textContent.trim().match(/(\d+)/);
                if (match) return parseInt(match[1], 10);
            }
            
            // Запасной вариант - ищем любой текст с процентами
            const info = cell.querySelector('.tasks-flow__list-members_info');
            if (info) {
                const match = info.textContent.trim().match(/(\d+)%/);
                if (match) return parseInt(match[1], 10);
            }
            
            // Если ничего не найдено, проверяем весь текст ячейки
            const text = cell.textContent.trim();
            const match = text.match(/(\d+)%/);
            if (match) return parseInt(match[1], 10);
            
            return null;
        }
        
        function getTaskCount(row, columnId) {
            const cell = row.querySelector(`td[data-column-id="${columnId}"]`);
            if (!cell) {
                console.log(`getTaskCount: ячейка с columnId="${columnId}" не найдена`);
                return 0;
            }
            
            // Пробуем получить из data-total
            const membersWrapper = cell.querySelector('.tasks-flow__list-members');
            if (membersWrapper && membersWrapper.getAttribute('data-total')) {
                const total = parseInt(membersWrapper.getAttribute('data-total'));
                if (!isNaN(total)) {
                    console.log(`getTaskCount: найдено data-total=${total} для ${columnId}`);
                    return total;
                }
            }
            
            // Запасной вариант - из текста
            const info = cell.querySelector('.tasks-flow__list-members_info');
            if (info) {
                const match = info.textContent.trim().match(/(\d+)/);
                if (match) {
                    const count = parseInt(match[1], 10);
                    console.log(`getTaskCount: найдено в .tasks-flow__list-members_info=${count} для ${columnId}`);
                    return count;
                }
            }
            
            // Дополнительный вариант - искать любой текст с числами в ячейке
            const content = cell.querySelector('.main-grid-cell-content');
            if (content) {
                const match = content.textContent.trim().match(/(\d+)/);
                if (match) {
                    const count = parseInt(match[1], 10);
                    console.log(`getTaskCount: найдено в .main-grid-cell-content=${count} для ${columnId}`);
                    return count;
                }
            }
            
            // Если ничего не найдено, проверяем сам текст ячейки
            const text = cell.textContent.trim();
            const match = text.match(/(\d+)/);
            if (match) {
                const count = parseInt(match[1], 10);
                console.log(`getTaskCount: найдено в cell.textContent=${count} для ${columnId}`);
                return count;
            }
            
            console.log(`getTaskCount: не найдено чисел для ${columnId}, текст ячейки: "${text}"`);
            return 0;
        }

        function getMyTasksCount(row) {
            const cell = row.querySelector('td[data-column-id="MY_TASKS"]');
            if (!cell) return 0;
            
            // Пробуем найти span с количеством
            const myTasksSpan = cell.querySelector('.tasks-flow__list-my-tasks span');
            if (myTasksSpan) {
                const match = myTasksSpan.textContent.trim().match(/(\d+)/);
                if (match) return parseInt(match[1], 10);
            }
            
            // Запасной вариант - ищем любой текст с числами
            const content = cell.querySelector('.main-grid-cell-content');
            if (content) {
                const match = content.textContent.trim().match(/(\d+)/);
                if (match) return parseInt(match[1], 10);
            }
            
            // Если ничего не найдено, проверяем сам текст ячейки
            const text = cell.textContent.trim();
            const match = text.match(/(\d+)/);
            if (match) return parseInt(match[1], 10);
            
            return 0;
        }
        
        function formatMyTasksText(count) {
            if (count === 0) return 'Нет моих задач';
            const lastDigit = count % 10;
            const lastTwo = count % 100;
            if (lastDigit === 1 && lastTwo !== 11) return count + ' задача';
            if (lastDigit >= 2 && lastDigit <= 4 && (lastTwo < 10 || lastTwo >= 20)) return count + ' задачи';
            return count + ' задач';
        }

        function getAvatarsFromCell(row, columnId) {
            const cell = row.querySelector(`td[data-column-id="${columnId}"]`);
            if (!cell) return [];
            
            const avatars = [];
            // Ищем элементы tasks-flow__list-members-icon_element с background-image
            const iconElements = cell.querySelectorAll('.tasks-flow__list-members-icon_element:not(.--count)');
            
            iconElements.forEach(el => {
                const style = el.getAttribute('style');
                if (style && style.includes('background-image')) {
                    const match = style.match(/background-image:\s*url\(['"]?([^'")]+)['"]?\)/);
                    if (match) {
                        const url = match[1];
                        if (!avatars.includes(url)) {
                            avatars.push(url);
                        }
                    }
                }
            });
            
            // Ограничим максимум 3 аватарки
            return avatars.slice(0, 3);
        }

        function generateAvatarsHTML(avatarUrls) {
            if (avatarUrls.length === 0) {
                // Если аватарок нет, возвращаем иконку по умолчанию
                return `<div class="tasks-flow__list-members-icon_element --icon">
                    <div class="ui-icon-set --person"></div>
                </div>`;
            }
            
            // Генерируем HTML для каждой аватарки
            const avatarElements = avatarUrls.map(url =>
                `<div class="tasks-flow__list-members-icon_element" style="background-image: url('${url}');"></div>`
            ).join('');
            
            return avatarElements;
        }
        
        function applyGrouping() {
            console.log('applyGrouping вызвана');
            
            const gridTable = document.querySelector('.main-grid-table');
            if (!gridTable) {
                console.log('Таблица не найдена');
                return false;
            }
            
            const tbody = gridTable.querySelector('tbody');
            if (!tbody) return false;
            
            if (document.querySelector('.custom-group-row')) {
                console.log('Группировка уже есть');
                return true;
            }
            
            const allRows = Array.from(tbody.querySelectorAll('tr.main-grid-row-body:not(.main-grid-not-count)'));
            if (allRows.length === 0) return false;
            
            console.log('Найдено строк:', allRows.length);
            
            const groupedRows = new Map();
            const ungroupedRows = [];
            
            allRows.forEach(row => {
                const flowId = getFlowIdFromRow(row);
                let assigned = false;
                
                for (const group of GROUPS_CONFIG) {
                    if (group.FLOW_IDS && group.FLOW_IDS.includes(flowId)) {
                        if (!groupedRows.has(group.ID)) groupedRows.set(group.ID, []);
                        groupedRows.get(group.ID).push(row);
                        assigned = true;
                        break;
                    }
                }
                if (!assigned) ungroupedRows.push(row);
            });
            
            if (groupedRows.size === 0) return false;
            
            while (tbody.firstChild) tbody.removeChild(tbody.firstChild);
            
            for (const group of GROUPS_CONFIG) {
                const rows = groupedRows.get(group.ID);
                if (!rows || rows.length === 0) continue;
                
                let latestActivity = null;
                let totalEfficiency = 0, effCount = 0;
                let totalPending = 0, totalAtWork = 0, totalCompleted = 0, totalMyTasks = 0;
                const pendingAvatars = [];
                const atWorkAvatars = [];
                const completedAvatars = [];
                
                rows.forEach(row => {
                    const activity = getActivityDate(row);
                    if (activity && (!latestActivity || activity > latestActivity)) latestActivity = activity;
                    
                    const eff = getEfficiency(row);
                    if (eff !== null) { totalEfficiency += eff; effCount++; }
                    
                    totalPending += getTaskCount(row, 'PENDING');
                    totalAtWork += getTaskCount(row, 'AT_WORK');
                    totalCompleted += getTaskCount(row, 'COMPLETED');
                    totalMyTasks += getMyTasksCount(row);
                    
                    // Собираем аватарки из каждой строки
                    const rowPendingAvatars = getAvatarsFromCell(row, 'PENDING');
                    const rowAtWorkAvatars = getAvatarsFromCell(row, 'AT_WORK');
                    const rowCompletedAvatars = getAvatarsFromCell(row, 'COMPLETED');
                    
                    rowPendingAvatars.forEach(url => {
                        if (!pendingAvatars.includes(url)) pendingAvatars.push(url);
                    });
                    rowAtWorkAvatars.forEach(url => {
                        if (!atWorkAvatars.includes(url)) atWorkAvatars.push(url);
                    });
                    rowCompletedAvatars.forEach(url => {
                        if (!completedAvatars.includes(url)) completedAvatars.push(url);
                    });
                });
                
                // Ограничиваем максимум 3 аватарки на колонку
                const finalPendingAvatars = pendingAvatars.slice(0, 3);
                const finalAtWorkAvatars = atWorkAvatars.slice(0, 3);
                const finalCompletedAvatars = completedAvatars.slice(0, 3);
                
                const avgEff = effCount > 0 ? Math.round(totalEfficiency / effCount) : 100;
                const avgEffClass = avgEff >= 80 ? '' : (avgEff >= 60 ? '--warning' : '--danger');
                const myTasksText = formatMyTasksText(totalMyTasks);
                const activityText = latestActivity || 'Нет данных';
                const isExpanded = group.EXPANDED === 'Y';
                const hasDesc = group.DESCRIPTION && group.DESCRIPTION.trim() !== '';
                
                const groupRow = document.createElement('tr');
                groupRow.className = 'main-grid-row main-grid-row-body custom-group-row';
                groupRow.id = `custom-group-${group.ID}`;
                groupRow.setAttribute('data-expanded', isExpanded ? 'true' : 'false');
                // Убираем style.backgroundColor - пусть наследуется от класса
                groupRow.style.borderBottom = '1px solid #eef2f4';
                
                groupRow.innerHTML = `
                    <td class="main-grid-cell main-grid-cell-action" style="vertical-align: middle; width: 45px;">
                        <span class="main-grid-cell-content">
                            <span class="ui-icon-set ${isExpanded ? '--chevron-down' : '--chevron-right'} custom-group-toggle-icon" style="--ui-icon-set__icon-size: 18px; --ui-icon-set__icon-color: #828b95; cursor: pointer;" data-group-id="${group.ID}"></span>
                        </span>
                    </td>
                    <td class="main-grid-cell main-grid-cell-left" data-column-id="NAME">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap;">
                                    <span class="tasks-flow__list-name_flow-name">${group.NAME}</span>
                                    <span class="custom-group-count">(${rows.length})</span>
                                </div>
                                ${hasDesc ? `<div class="custom-group-description">${group.DESCRIPTION}</div>` : ''}
                            </div>
                        </div>
                    </td>
                    <td class="main-grid-cell main-grid-cell-left" data-column-id="ACTIVITY">
                        <div class="main-grid-cell-inner">
                            <span class="main-grid-cell-content">
                                <div class="tasks-flow__list-cell">${activityText}</div>
                            </span>
                        </div>
                    </td>
                    <td class="main-grid-cell main-grid-cell-right" data-column-id="MY_TASKS">
                        <div class="main-grid-cell-inner">
                            <span class="main-grid-cell-content">
                                <div class="tasks-flow__list-cell --right">${myTasksText}</div>
                            </span>
                        </div>
                    </td>
                    <td class="main-grid-cell main-grid-cell-left" data-column-id="CREATE_TASK">
                        <div class="main-grid-cell-inner">
                            <span class="main-grid-cell-content">
                                <div class="tasks-flow__list-cell">
                                    <div class="custom-group-expand-toggle ${isExpanded ? '--minus' : '--plus'}" data-group-id="${group.ID}" title="${isExpanded ? 'Свернуть' : 'Развернуть'}">
                                        <span class="custom-group-expand-icon"></span>
                                    </div>
                                </div>
                            </span>
                        </div>
                    </td>
                    <td class="main-grid-cell main-grid-cell-center" data-column-id="PENDING">
                        <div class="main-grid-cell-inner">
                            <span class="main-grid-cell-content">
                                <div class="tasks-flow__list-cell --middle">
                                    <div class="tasks-flow__list-members_wrapper --link">
                                        <div class="tasks-flow__list-cell_line --middle">
                                            <div class="tasks-flow__list-members">
                                                ${generateAvatarsHTML(finalPendingAvatars)}
                                            </div>
                                        </div>
                                        <div class="tasks-flow__list-members_info">${totalPending}</div>
                                    </div>
                                </div>
                            </span>
                        </div>
                    </td>
                    <td class="main-grid-cell main-grid-cell-center" data-column-id="AT_WORK">
                        <div class="main-grid-cell-inner">
                            <span class="main-grid-cell-content">
                                <div class="tasks-flow__list-cell --middle">
                                    <div class="tasks-flow__list-members_wrapper --link">
                                        <div class="tasks-flow__list-cell_line --middle">
                                            <div class="tasks-flow__list-members">
                                                ${generateAvatarsHTML(finalAtWorkAvatars)}
                                            </div>
                                        </div>
                                        <div class="tasks-flow__list-members_info">${totalAtWork}</div>
                                    </div>
                                </div>
                            </span>
                        </div>
                    </td>
                    <td class="main-grid-cell main-grid-cell-center" data-column-id="EFFICIENCY">
                        <div class="main-grid-cell-inner">
                            <span class="main-grid-cell-content">
                                <div class="tasks-flow__list-cell --middle">
                                    <div class="tasks-flow__list-members_wrapper --link">
                                        <div class="tasks-flow__list-cell_line --middle">
                                            <div class="tasks-flow__efficiency-chart ${avgEffClass}"></div>
                                        </div>
                                        <div class="tasks-flow__list-members_info --link --efficiency">${avgEff}%</div>
                                    </div>
                                </div>
                            </span>
                        </div>
                    </td>
                    <td class="main-grid-cell main-grid-cell-center" data-column-id="COMPLETED">
                        <div class="main-grid-cell-inner">
                            <span class="main-grid-cell-content">
                                <div class="tasks-flow__list-cell">
                                    <div class="tasks-flow__list-members_wrapper --link">
                                        <div class="tasks-flow__list-cell_line --middle --end-line">
                                            <div class="tasks-flow__list-members">
                                                ${generateAvatarsHTML(finalCompletedAvatars)}
                                            </div>
                                        </div>
                                        <div class="tasks-flow__list-members_info">${totalCompleted}</div>
                                    </div>
                                </div>
                            </span>
                        </div>
                    </td>
                <td class="main-grid-cell main-grid-cell-left" data-column-id="OWNER_ID">
                    <div class="main-grid-cell-inner">
                        <span class="main-grid-cell-content">
                            <div class="tasks-flow__list-cell">
                                <div class="tasks-flow__list-owner-wrapper">
                                    ${group.OWNER_ID && group.OWNER_ID > 0 ?
                                        `<a href="/company/personal/user/${group.OWNER_ID}/" class="tasks-flow__list-owner" target="_top">
                                            <span class="tasks-flow__list-owner-photo ui-icon ui-icon-common-user">
                                                <i style="background-image: url('${group.OWNER_AVATAR ? group.OWNER_AVATAR : '/bitrix/js/im/images/placeholder-user.png'}'); background-size: cover; background-position: center;"></i>
                                            </span>
                                            <span>${group.OWNER_NAME || 'Пользователь ' + group.OWNER_ID}</span>
                                        </a>` :
                                        `<div class="tasks-flow__list-owner">
                                            <div class="tasks-flow__list-members-icon_element --icon">
                                                <div class="ui-icon-set --person"></div>
                                            </div>
                                            <span>${rows.length} потоков</span>
                                        </div>`
                                    }
                                </div>
                            </div>
                        </span>
                    </div>
                </td>
                `;
                tbody.appendChild(groupRow);
                
            const childRows = [];
            rows.forEach(row => {
                const nameCell = row.querySelector('td[data-column-id="NAME"]');
                if (nameCell) {
                    // Убираем лишний отступ - оставляем как есть
                    nameCell.style.paddingLeft = '';
                }
                if (!isExpanded) row.style.display = 'none';
                tbody.appendChild(row);
                childRows.push(row);
            });
                groupRow._childRows = childRows;
            }
            
            ungroupedRows.forEach(row => tbody.appendChild(row));
            
            document.querySelectorAll('.custom-group-toggle').forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const groupId = this.dataset.groupId;
                    const groupRow = document.getElementById(`custom-group-${groupId}`);
                    const icon = this.querySelector('.ui-icon-set');
                    const expandToggle = groupRow.querySelector('.custom-group-expand-toggle');
                    const isExpanded = groupRow.getAttribute('data-expanded') === 'true';
                    const childRows = groupRow._childRows || [];
                    
                    if (isExpanded) {
                        childRows.forEach(row => row.style.display = 'none');
                        icon?.classList.replace('--chevron-down', '--chevron-right');
                        if (expandToggle) {
                            expandToggle.classList.remove('--minus');
                            expandToggle.classList.add('--plus');
                            expandToggle.title = 'Развернуть';
                        }
                        groupRow.setAttribute('data-expanded', 'false');
                    } else {
                        childRows.forEach(row => row.style.display = '');
                        icon?.classList.replace('--chevron-right', '--chevron-down');
                        if (expandToggle) {
                            expandToggle.classList.remove('--plus');
                            expandToggle.classList.add('--minus');
                            expandToggle.title = 'Свернуть';
                        }
                        groupRow.setAttribute('data-expanded', 'true');
                    }
                });
            });
            
            // Обработчик для иконок разворачивания (стрелка в первой колонке)
            document.querySelectorAll('.custom-group-toggle-icon').forEach(icon => {
                icon.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const groupId = this.dataset.groupId;
                    const groupRow = document.getElementById(`custom-group-${groupId}`);
                    const expandToggle = groupRow.querySelector('.custom-group-expand-toggle');
                    const isExpanded = groupRow.getAttribute('data-expanded') === 'true';
                    const childRows = groupRow._childRows || [];
                    
                    if (isExpanded) {
                        childRows.forEach(row => row.style.display = 'none');
                        this.classList.remove('--chevron-down');
                        this.classList.add('--chevron-right');
                        if (expandToggle) {
                            expandToggle.classList.remove('--minus');
                            expandToggle.classList.add('--plus');
                            expandToggle.title = 'Развернуть';
                        }
                        groupRow.setAttribute('data-expanded', 'false');
                    } else {
                        childRows.forEach(row => row.style.display = '');
                        this.classList.remove('--chevron-right');
                        this.classList.add('--chevron-down');
                        if (expandToggle) {
                            expandToggle.classList.remove('--plus');
                            expandToggle.classList.add('--minus');
                            expandToggle.title = 'Свернуть';
                        }
                        groupRow.setAttribute('data-expanded', 'true');
                    }
                });
            });

            // Обработчики для переключателя разворачивания (плюсик/минус в кружке)
            document.querySelectorAll('.custom-group-expand-toggle').forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const groupId = this.dataset.groupId;
                    const groupRow = document.getElementById(`custom-group-${groupId}`);
                    const chevronIcon = groupRow.querySelector('.custom-group-toggle-icon');
                    const isExpanded = groupRow.getAttribute('data-expanded') === 'true';
                    const childRows = groupRow._childRows || [];
                    
                    if (isExpanded) {
                        // Сворачиваем
                        childRows.forEach(row => row.style.display = 'none');
                        chevronIcon?.classList.remove('--chevron-down');
                        chevronIcon?.classList.add('--chevron-right');
                        this.classList.remove('--minus');
                        this.classList.add('--plus');
                        this.title = 'Развернуть';
                        groupRow.setAttribute('data-expanded', 'false');
                    } else {
                        // Разворачиваем
                        childRows.forEach(row => row.style.display = '');
                        chevronIcon?.classList.remove('--chevron-right');
                        chevronIcon?.classList.add('--chevron-down');
                        this.classList.remove('--plus');
                        this.classList.add('--minus');
                        this.title = 'Свернуть';
                        groupRow.setAttribute('data-expanded', 'true');
                    }
                });
            });
            
            console.log('Группировка применена!');
            isGroupingApplied = true;
            // Показываем таблицу после успешной группировки
            if (!isTableShown && typeof window.FlowGroupsShowTable === 'function') {
                window.FlowGroupsShowTable();
                isTableShown = true;
            }
            return true;
        }
        
        let attempts = 0;
        const maxAttempts = 30;
        
        function tryApplyGrouping() {
            attempts++;
            console.log('Попытка группировки #' + attempts);
            
            if (applyGrouping()) {
                console.log('Успех!');
                return;
            }
            
            if (attempts < maxAttempts) {
                setTimeout(tryApplyGrouping, 100);
            } else {
                console.log('Не удалось применить группировку');
            }
        }
        
        setTimeout(tryApplyGrouping, 100);
        
        if (typeof BX !== 'undefined') {
            BX.addCustomEvent('Grid::updated', function() {
                console.log('Grid обновлён');
                isGroupingApplied = false;
                setTimeout(applyGrouping, 300);
            });
        }
    }
    
    waitForData();
})();