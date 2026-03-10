(function() {
    'use strict';
    
    const MIN_REPORT_CHARS = 75;
    const CHECK_INTERVAL_MS = 500;
    const REPORT_IFRAME_SELECTOR = 'iframe[id*="LHE_iframe_obReportWeekly"]';
    const SEND_BUTTON_ID = 'tm-work-report-send';
    const STATUS_CONTAINER_ID = 'report-length-status';
    
    let observer = null;
    let checkInterval = null;
    let attachedButtons = new WeakSet();
    let lastKnownLength = 0;

    function getReportLength() {
        const iframes = document.querySelectorAll(REPORT_IFRAME_SELECTOR);
        if (iframes.length === 0) return 0;
        try {
            const iframe = iframes[0];
            const doc = iframe.contentDocument || iframe.contentWindow.document;
            const text = (doc.body.innerText || doc.body.textContent || '').trim();
            return text.length;
        } catch (e) { 
            console.log('[Validator] Ошибка чтения iframe:', e);
            return 0; 
        }
    }

    function validateAndToggle() {
        // Ищем попап и кнопку
        const popup = document.querySelector('.popup-window.--open');
        if (!popup) return;
        
        const sendBtn = popup.querySelector('#' + SEND_BUTTON_ID);
        let statusBox = popup.querySelector('#' + STATUS_CONTAINER_ID);

        // Создаем контейнер статуса
        if (!statusBox && sendBtn) {
            statusBox = document.createElement('div');
            statusBox.id = STATUS_CONTAINER_ID;
            if (sendBtn.parentNode) {
                sendBtn.parentNode.insertBefore(statusBox, sendBtn.nextSibling);
            }
            console.log('[Validator] Создан индикатор статуса');
        }

        if (!sendBtn && !statusBox) return;

        const length = getReportLength();
        const isValid = length >= MIN_REPORT_CHARS;
        
        // Логирование
        if (length !== lastKnownLength) {
            console.log('[Validator] Длина текста:', length, 'Валидно:', isValid);
            lastKnownLength = length;
        }

        // Перехват кнопки
        if (sendBtn && !attachedButtons.has(sendBtn)) {
            sendBtn.addEventListener('click', function(e) {
                const currentLength = getReportLength();
                const currentValid = currentLength >= MIN_REPORT_CHARS;
                
                if (!currentValid) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    showTemporaryAlert('Отчет слишком короткий! Нужно минимум ' + MIN_REPORT_CHARS + ' символов.');
                    return false;
                }
            }, true);
            
            attachedButtons.add(sendBtn);
            console.log('[Validator] Обработчик кнопки установлен');
        }

        // Визуальное состояние кнопки
        if (sendBtn) {
            if (!isValid) {
                sendBtn.style.pointerEvents = 'none';
                sendBtn.style.opacity = '0.6';
                sendBtn.style.cursor = 'not-allowed';
                sendBtn.title = 'Минимум ' + MIN_REPORT_CHARS + ' символов';
            } else {
                sendBtn.style.pointerEvents = '';
                sendBtn.style.opacity = '1';
                sendBtn.style.cursor = 'pointer';
                sendBtn.title = '';
            }
        }

        // Логика индикатора
        if (statusBox) {
            if (length === 0) {
                statusBox.style.display = 'none';
                return;
            }
            statusBox.style.display = 'block';
            
            if (isValid) {
                statusBox.innerHTML = '<div style="color:#3c763d;background:#dff0d8;border:1px solid #d6e9c6;padding:8px 12px;border-radius:4px;font-size:13px;margin-top:10px">✓ Отчет готов ('+length+'/'+MIN_REPORT_CHARS+' зн.)</div>';
            } else {
                statusBox.innerHTML = '<div style="color:#a94442;background:#f2dede;border:1px solid #ebccd1;padding:8px 12px;border-radius:4px;font-size:13px;margin-top:10px">⚠ Нужно еще '+(MIN_REPORT_CHARS - length)+' зн. ('+length+'/'+MIN_REPORT_CHARS+')</div>';
            }
        }
    }

    function showTemporaryAlert(message) {
        const old = document.querySelector('.report-validator-alert');
        if (old) old.remove();
        
        const alert = document.createElement('div');
        alert.className = 'report-validator-alert';
        alert.textContent = message;
        alert.style.cssText = 'position:fixed;top:20px;right:20px;background:#f2dede;color:#a94442;padding:12px 20px;border:1px solid #ebccd1;border-radius:4px;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;';
        document.body.appendChild(alert);
        
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.3s';
            setTimeout(() => alert.remove(), 300);
        }, 3000);
    }

    function initPopupObserver() {
        if (observer) observer.disconnect();
        
        observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1) {
                        if (node.classList && node.classList.contains('popup-window') && node.classList.contains('--open')) {
                            if (!node.dataset.validatorAttached) {
                                node.dataset.validatorAttached = 'true';
                                setTimeout(() => validateAndToggle(), 200);
                            }
                        }
                        const popup = node.querySelector ? node.querySelector('.popup-window.--open') : null;
                        if (popup && !popup.dataset.validatorAttached) {
                            popup.dataset.validatorAttached = 'true';
                            setTimeout(() => validateAndToggle(), 200);
                        }
                    }
                });
            });
        });
        
        observer.observe(document.body, { childList: true, subtree: true });
    }

    function init() {
        console.log('[Validator] Модуль запущен');
        initPopupObserver();
        
        setTimeout(() => {
            validateAndToggle();
        }, 300);

        checkInterval = setInterval(() => {
            validateAndToggle();
        }, CHECK_INTERVAL_MS);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        setTimeout(init, 100);
    }
})();