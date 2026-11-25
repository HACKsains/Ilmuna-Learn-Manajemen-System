/**
 * ==============================================================================
 * MARGO ONE SSO — REAL-TIME WEBSOCKET NOTIFICATION WIDGET (ZERO POLLING)
 * ==============================================================================
 * Menghubungkan dashboard/topbar Margo One SSO (`margo-one.ilmuna.my.id`) langsung
 * ke Daemon WebSocket RFC 6455 `learn.ilmuna.my.id` menggunakan Ephemeral Ticket (`wst_*`).
 *
 * Fitur:
 *   - 100% Real-Time Push melalui WebSocket (TANPA polling periodik / TANPA reload halaman)
 *   - Auto-Reconnect cerdas (exponential backoff) dengan endpoint refresh tiket SSO
 *   - Re-sync missed events (`sync_since`) otomatis bila koneksi internet sempat terputus
 *   - Widget Lonceng Notifikasi + Pop-up Toast Real-Time + Audio Ping lembut opsional
 *     + Integrasi mulus ke `window.Ilmuna.toast()` jika tersedia
 *
 * Pemakaian:
 *   IlmunaSsoRealtime.mount({
 *       wsConnectUrl: "wss://learn.ilmuna.my.id/ws/sso?ticket=wst_...",
 *       refreshTicketEndpoint: "/api/refresh-learn-ws-ticket.php",
 *       mountBellSelector: "#ssoNotifBellMount",
 *       onEvent: function (evt) { ... },
 *       onSnapshot: function (snapshot) { ... }
 *   });
 * ==============================================================================
 */
(function (global) {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function catMeta(cat) {
        switch (String(cat || '').toLowerCase()) {
            case 'tugas':    return { label: 'Tugas', color: '#0D7A87', bg: '#CCFBF1', icon: 'solar:document-text-bold' };
            case 'proyek':   return { label: 'Proyek P5', color: '#D97706', bg: '#FEF3C7', icon: 'solar:rocket-2-bold' };
            case 'kelompok': return { label: 'Kelompok', color: '#7C3AED', bg: '#EDE9FE', icon: 'solar:users-group-rounded-bold' };
            case 'asesmen':  return { label: 'Asesmen', color: '#DC2626', bg: '#FEE2E2', icon: 'solar:alarm-bold' };
            default:         return { label: 'Info', color: '#1D3B5E', bg: '#E0E7FF', icon: 'solar:bell-bing-bold' };
        }
    }

    function injectStyles() {
        if (document.getElementById('ilmuna-sso-rt-styles')) return;
        var css = [
            '.ilm-sso-bell-wrap{position:relative;display:inline-flex;align-items:center;z-index:60;isolation:isolate;font-family:"Plus Jakarta Sans",sans-serif;}',
            '.ilm-sso-bell-btn{position:relative;width:42px;height:42px;border-radius:12px;border:1px solid #CBD5E1;background:#FFFFFF;color:#1D3B5E;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 12px rgba(29,59,94,.08);transition:all .18s;}',
            '.ilm-sso-bell-btn:hover{border-color:#0D7A87;color:#0D7A87;transform:translateY(-1px);}',
            '.ilm-sso-live-dot{position:absolute;left:7px;bottom:7px;width:8px;height:8px;border-radius:50%;background:#94A3B8;border:1.5px solid #fff;}',
            '.ilm-sso-live-dot.is-live{background:#10B981;box-shadow:0 0 0 3px rgba(16,185,129,.22);}',
            '.ilm-sso-badge-cnt{position:absolute;top:-5px;right:-5px;min-width:19px;height:19px;padding:0 5px;border-radius:999px;background:linear-gradient(135deg,#D97706,#EF4444);color:#fff;font-size:10.5px;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid #fff;}',
            '.ilm-sso-badge-cnt.has-unread{display:inline-flex;}',
            '.ilm-sso-drawer{position:absolute;top:50px;right:0;width:min(380px,calc(100vw - 24px));background:#FFFFFF;border:1px solid #E2E8F0;border-radius:20px;box-shadow:0 24px 54px rgba(15,29,47,.22);display:none;flex-direction:column;overflow:hidden;z-index:9999;}',
            '.ilm-sso-drawer.is-open{display:flex;}',
            '.ilm-sso-drawer-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:linear-gradient(135deg,#1D3B5E,#0D7A87);color:#fff;}',
            '.ilm-sso-drawer-list{max-height:380px;overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:8px;background:#F8FAFC;}',
            '.ilm-sso-notif-card{background:#FFFFFF;border:1px solid #E2E8F0;border-radius:14px;padding:11px 13px;display:flex;flex-direction:column;gap:4px;text-decoration:none;color:inherit;transition:border-color .18s;}',
            '.ilm-sso-notif-card:hover{border-color:#0D7A87;}',
            '.ilm-sso-toast-stack{position:fixed;top:20px;right:20px;z-index:2147483640;display:flex;flex-direction:column;gap:10px;max-width:360px;pointer-events:none;}',
            '.ilm-sso-toast-item{pointer-events:auto;background:#FFFFFF;border:1px solid #E2E8F0;border-left:4px solid #0D7A87;border-radius:14px;padding:12px 14px;box-shadow:0 16px 38px rgba(15,29,47,.2);font-family:"Plus Jakarta Sans",sans-serif;animation:ilmSsoToastIn .25s ease;}',
            '@keyframes ilmSsoToastIn{from{opacity:0;transform:translateX(20px);}to{opacity:1;transform:translateX(0);}}'
        ].join('');
        var st = document.createElement('style');
        st.id = 'ilmuna-sso-rt-styles';
        st.textContent = css;
        document.head.appendChild(st);
    }

    var IlmunaSsoRealtime = {
        ws: null,
        opts: {},
        events: [],
        unreadCount: 0,
        lastEventId: 0,
        reconnectAttempts: 0,
        manualClose: false,
        pingTimer: null,

        mount: function (options) {
            this.opts = options || {};
            this.manualClose = false;
            injectStyles();
            if (this.opts.mountBellSelector) {
                this.renderBellWidget(this.opts.mountBellSelector);
            }
            if (Array.isArray(this.opts.initialEvents)) {
                this.ingestInitial(this.opts.initialEvents);
            }
            if (this.opts.wsConnectUrl) {
                this.connect(this.opts.wsConnectUrl);
            }
            return this;
        },

        ingestInitial: function (list) {
            var self = this;
            list.forEach(function (ev) {
                if (ev.event_id && ev.event_id > self.lastEventId) {
                    self.lastEventId = Number(ev.event_id);
                }
                self.events.push(ev);
            });
            this.refreshListDom();
        },

        connect: function (url) {
            var self = this;
            if (!url || typeof WebSocket === 'undefined') return;
            if (self.ws) {
                try { self.ws.close(); } catch (e) {}
            }

            var ws = new WebSocket(url);
            self.ws = ws;

            ws.onopen = function () {
                self.reconnectAttempts = 0;
                self.setLiveStatus(true);
                if (self.lastEventId > 0) {
                    ws.send(JSON.stringify({ action: 'sync_since', since_event_id: self.lastEventId }));
                }
                clearInterval(self.pingTimer);
                self.pingTimer = setInterval(function () {
                    if (ws.readyState === WebSocket.OPEN) {
                        ws.send(JSON.stringify({ action: 'ping' }));
                    }
                }, 25000);
            };

            ws.onmessage = function (msgEvent) {
                var data;
                try { data = JSON.parse(msgEvent.data); } catch (e) { return; }
                if (!data || !data.type) return;

                if (data.type === 'ilmuna.realtime.welcome') {
                    if (data.last_event_id && Number(data.last_event_id) > self.lastEventId) {
                        self.lastEventId = Number(data.last_event_id);
                    }
                    if (data.snapshot && typeof self.opts.onSnapshot === 'function') {
                        self.opts.onSnapshot(data.snapshot);
                    }
                } else if (data.type === 'ilmuna.realtime.replay' && Array.isArray(data.events)) {
                    data.events.forEach(function (ev) {
                        self.handleIncomingEvent(ev, false);
                    });
                } else if (data.type === 'ilmuna.realtime.event') {
                    self.handleIncomingEvent(data, true);
                }
            };

            ws.onclose = function () {
                self.setLiveStatus(false);
                clearInterval(self.pingTimer);
                if (!self.manualClose) {
                    self.scheduleReconnect();
                }
            };
        },

        scheduleReconnect: function () {
            var self = this;
            self.reconnectAttempts++;
            var delay = Math.min(30000, 1200 * Math.pow(1.6, Math.min(self.reconnectAttempts, 6)));
            setTimeout(async function () {
                if (self.manualClose) return;
                if (self.opts.refreshTicketEndpoint) {
                    try {
                        var r = await fetch(self.opts.refreshTicketEndpoint, { credentials: 'same-origin' });
                        var j = await r.json();
                        if (j && j.ws_connect_url) {
                            self.connect(j.ws_connect_url);
                            return;
                        }
                    } catch (e) {}
                }
                if (self.opts.wsConnectUrl) {
                    self.connect(self.opts.wsConnectUrl);
                }
            }, delay);
        },

        handleIncomingEvent: function (ev, showToast) {
            if (!ev) return;
            if (ev.event_id && Number(ev.event_id) <= this.lastEventId && this.events.some(function (x) { return Number(x.event_id) === Number(ev.event_id); })) {
                return;
            }
            if (ev.event_id && Number(ev.event_id) > this.lastEventId) {
                this.lastEventId = Number(ev.event_id);
            }
            this.events.unshift(ev);
            if (this.events.length > 40) this.events.pop();

            this.unreadCount++;
            this.refreshListDom();

            if (showToast) {
                this.showRealtimeToast(ev);
            }
            if (typeof this.opts.onEvent === 'function') {
                this.opts.onEvent(ev);
            }
        },

        showRealtimeToast: function (ev) {
            var meta = catMeta(ev.category);
            if (global.Ilmuna && typeof global.Ilmuna.toast === 'function') {
                global.Ilmuna.toast('info', ev.title, ev.summary || meta.label, 6500);
                return;
            }
            var stack = document.getElementById('ilmSsoRtToastStack');
            if (!stack) {
                stack = document.createElement('div');
                stack.id = 'ilmSsoRtToastStack';
                stack.className = 'ilm-sso-toast-stack';
                document.body.appendChild(stack);
            }
            var card = document.createElement('div');
            card.className = 'ilm-sso-toast-item';
            card.style.borderLeftColor = meta.color;
            card.innerHTML =
                '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:4px;">' +
                '<span style="font-size:11px;font-weight:800;color:' + meta.color + ';background:' + meta.bg + ';padding:2px 8px;border-radius:999px;">' + esc(meta.label) + ' · ' + esc(ev.kelas && ev.kelas.nama ? ev.kelas.nama : 'Kelas') + '</span>' +
                '<button type="button" style="border:none;background:none;cursor:pointer;font-size:15px;color:#64748B;" onclick="this.closest(\'.ilm-sso-toast-item\').remove()">&times;</button>' +
                '</div>' +
                '<div style="font-size:13px;font-weight:700;color:#0F1D2F;">' + esc(ev.title) + '</div>' +
                (ev.summary ? '<div style="font-size:12px;color:#475569;margin-top:2px;">' + esc(ev.summary) + '</div>' : '');
            stack.appendChild(card);
            setTimeout(function () { if (card.parentNode) card.remove(); }, 7000);
        },

        renderBellWidget: function (selector) {
            var mountEl = document.querySelector(selector);
            if (!mountEl) return;
            var self = this;
            mountEl.innerHTML =
                '<div class="ilm-sso-bell-wrap">' +
                '  <button type="button" class="ilm-sso-bell-btn" id="ilmSsoBellBtn" title="Notifikasi Akademik Real-Time">' +
                '    <iconify-icon icon="solar:bell-bing-bold" width="21"></iconify-icon>' +
                '    <span class="ilm-sso-live-dot" id="ilmSsoLiveDot"></span>' +
                '    <span class="ilm-sso-badge-cnt" id="ilmSsoUnreadBadge">0</span>' +
                '  </button>' +
                '  <div class="ilm-sso-drawer" id="ilmSsoNotifDrawer">' +
                '    <div class="ilm-sso-drawer-head">' +
                '      <div><strong style="font-size:14px;">Notifikasi Akademik Live</strong><div style="font-size:11px;opacity:.85;" id="ilmSsoConnText">Menghubungkan WebSocket...</div></div>' +
                '      <button type="button" style="border:none;background:rgba(255,255,255,.18);color:#fff;border-radius:8px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;" id="ilmSsoMarkReadBtn">Tandai Dibaca</button>' +
                '    </div>' +
                '    <div class="ilm-sso-drawer-list" id="ilmSsoNotifList"></div>' +
                '  </div>' +
                '</div>';

            document.getElementById('ilmSsoBellBtn').addEventListener('click', function (e) {
                e.stopPropagation();
                var d = document.getElementById('ilmSsoNotifDrawer');
                d.classList.toggle('is-open');
                if (d.classList.contains('is-open')) {
                    self.unreadCount = 0;
                    self.refreshBadgeDom();
                }
            });
            document.getElementById('ilmSsoMarkReadBtn').addEventListener('click', function () {
                self.unreadCount = 0;
                self.refreshBadgeDom();
            });
            document.addEventListener('click', function (e) {
                var d = document.getElementById('ilmSsoNotifDrawer');
                if (d && !mountEl.contains(e.target)) {
                    d.classList.remove('is-open');
                }
            });
        },

        setLiveStatus: function (isLive) {
            var dot = document.getElementById('ilmSsoLiveDot');
            var txt = document.getElementById('ilmSsoConnText');
            if (dot) dot.classList.toggle('is-live', !!isLive);
            if (txt) txt.textContent = isLive ? 'WebSocket Real-Time Aktif' : 'Menghubungkan ulang...';
        },

        refreshBadgeDom: function () {
            var b = document.getElementById('ilmSsoUnreadBadge');
            if (!b) return;
            b.textContent = this.unreadCount > 99 ? '99+' : String(this.unreadCount);
            b.classList.toggle('has-unread', this.unreadCount > 0);
        },

        refreshListDom: function () {
            this.refreshBadgeDom();
            var listEl = document.getElementById('ilmSsoNotifList');
            if (!listEl) return;
            if (!this.events.length) {
                listEl.innerHTML = '<div style="text-align:center;padding:24px;color:#64748B;font-size:12.5px;">Belum ada notifikasi real-time terbaru untuk kelas Anda.</div>';
                return;
            }
            listEl.innerHTML = this.events.map(function (ev) {
                var meta = catMeta(ev.category);
                var clsName = (ev.kelas && ev.kelas.nama) ? ev.kelas.nama : 'Semua Kelas';
                return '<a class="ilm-sso-notif-card" href="' + esc(ev.portal_url || '#') + '" target="_blank" rel="noopener">'
                    + '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">'
                    + '<span style="font-size:10.5px;font-weight:800;color:' + meta.color + ';background:' + meta.bg + ';padding:2px 8px;border-radius:999px;">' + esc(meta.label) + ' · ' + esc(clsName) + '</span>'
                    + '<span style="font-size:10.5px;color:#64748B;">' + esc(ev.created_at || 'Baru saja') + '</span>'
                    + '</div>'
                    + '<strong style="font-size:13px;color:#0F1D2F;">' + esc(ev.title) + '</strong>'
                    + (ev.summary ? '<span style="font-size:12px;color:#475569;">' + esc(ev.summary) + '</span>' : '')
                    + '</a>';
            }).join('');
        }
    };

    global.IlmunaSsoRealtime = IlmunaSsoRealtime;
})(window);
