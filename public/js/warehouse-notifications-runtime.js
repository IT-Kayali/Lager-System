(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
                const wrap = document.getElementById('warehouse-notification-wrap');
                const button = document.getElementById('warehouse-notification-button');
                const panel = document.getElementById('warehouse-notification-panel');
                const badge = document.getElementById('warehouse-notification-badge');
                const countLabel = document.getElementById('warehouse-notification-panel-count');
                const list = document.getElementById('warehouse-notification-list');

                if (!wrap || !button || !panel || !badge || !countLabel || !list) {
                    return;
                }

                const endpoint = wrap.dataset.notificationsEndpoint || '';

            if (!endpoint) {
                return;
            }

                function setOpen(open) {
                    panel.hidden = !open;
                    button.setAttribute('aria-expanded', open ? 'true' : 'false');
                }

                function renderNotifications(payload) {
                    const count = Number(payload?.unread_count || 0);
                    const items = Array.isArray(payload?.items) ? payload.items : [];

                    badge.textContent = count > 99 ? '99+' : String(count);
                    badge.classList.toggle('is-hidden', count < 1);
                    countLabel.textContent = String(count);

                    list.replaceChildren();

                    if (items.length === 0) {
                        const empty = document.createElement('div');
                        empty.className = 'warehouse-notification-empty';
                        empty.textContent = 'Keine neuen Lageraufträge oder Notizen.';
                        list.appendChild(empty);
                        return;
                    }

                    items.forEach(function (item) {
                        const link = document.createElement('a');
                        link.className = 'warehouse-notification-item';
                        link.href = item.open_url;

                        const icon = document.createElement('span');
                        icon.className = 'warehouse-notification-item-icon';

                        const iconElement = document.createElement('i');
                        iconElement.className = item.type === 'offer'
                            ? 'bi bi-receipt-cutoff'
                            : item.type === 'offer_note'
                                ? 'bi bi-chat-left-text'
                                : 'bi bi-shop';
                        icon.appendChild(iconElement);

                        const copy = document.createElement('span');
                        copy.className = 'warehouse-notification-item-copy';

                        const title = document.createElement('strong');
                        title.textContent = item.title || 'Neue Lager-Benachrichtigung';
                        copy.appendChild(title);

                        if (item.message) {
                            const message = document.createElement('small');
                            message.textContent = item.message;
                            copy.appendChild(message);
                        }

                        if (item.time) {
                            const time = document.createElement('time');
                            time.textContent = item.time;
                            copy.appendChild(time);
                        }

                        link.appendChild(icon);
                        link.appendChild(copy);
                        list.appendChild(link);
                    });
                }

                async function refreshNotifications() {
                    try {
                        const response = await fetch(endpoint, {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                        });

                        if (!response.ok) {
                            return;
                        }

                        renderNotifications(await response.json());
                    } catch (error) {
                        // Eine kurzzeitig fehlgeschlagene Aktualisierung darf die Oberfläche nicht stören.
                    }
                }

                button.addEventListener('click', function (event) {
                    event.stopPropagation();
                    setOpen(panel.hidden);
                    if (!panel.hidden) {
                        refreshNotifications();
                    }
                });

                document.addEventListener('visibilitychange', function () {
                    if (!document.hidden) {
                        refreshNotifications();
                    }
                });

                panel.addEventListener('click', function (event) {
                    event.stopPropagation();
                });

                document.addEventListener('click', function () {
                    setOpen(false);
                });

                window.setInterval(refreshNotifications, 20000);
            });
})();
