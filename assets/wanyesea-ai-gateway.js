(function ($) {
    'use strict';

    var CFG = window.wanyeseaAiGateway || {};
    var state = { relays: [], modes: [], capabilities: [] };
    var saveTimer = null;
    var dirty = false;
    var loading = false;

    function cfgReady() {
        return !!(CFG.restUrl && CFG.restNonce);
    }

    function $app() {
        return $('#wya-gateway-app[data-wya-gateway-app]');
    }

    function api(path, method, body) {
        return $.ajax({
            url: CFG.restUrl.replace(/\/$/, '') + path,
            method: method || 'GET',
            contentType: 'application/json',
            dataType: 'json',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', CFG.restNonce);
            },
            data: body ? JSON.stringify(body) : undefined,
        });
    }

    function esc(s) {
        return $('<div/>').text(s == null ? '' : String(s)).html();
    }

    function setStatus($mount, html, isError) {
        $mount.html(
            '<p class="' + (isError ? 'wya-gateway-error' : 'wya-gateway-loading muted-3-color em09') + '">'
            + (isError ? '' : '<i class="fa fa-spinner fa-spin"></i> ')
            + html + '</p>'
        );
    }

    function modeLabel(mode) {
        var found = (state.modes || []).filter(function (m) {
            return m.value === mode;
        })[0];
        return found ? found.label : mode;
    }

    function modeBadge(mode) {
        var cls = mode === 'anthropic' ? 'wya-badge--mode-anthropic' : 'wya-badge--mode-openai';
        return '<span class="wya-badge ' + cls + '">' + esc(modeLabel(mode)) + '</span>';
    }

    function isRelayEnabled(relay) {
        if (!relay || relay.enabled === undefined || relay.enabled === null) {
            return false;
        }
        var v = relay.enabled;
        return v === true || v === 1 || v === '1' || v === 'true' || v === 'on';
    }

    function readCardEnabled($card) {
        var $input = $card.find('[data-field="enabled"]');
        if (!$input.length) {
            return false;
        }
        var $switcher = $input.closest('.csf-field-switcher').find('.csf--switcher');
        if ($switcher.length && $switcher.hasClass('csf--active')) {
            return true;
        }
        return $input.prop('checked') === true;
    }

    function syncEnabledUi($card) {
        if (!$card || !$card.length) {
            return;
        }
        var on = readCardEnabled($card);
        $card.toggleClass('wya-gateway-card--enabled', on);
        var index = parseInt($card.attr('data-wya-gateway-index'), 10);
        if (!isNaN(index) && state.relays[index]) {
            state.relays[index].enabled = on;
        }
    }

    function initGatewaySwitchers($root) {
        $root.find('.wya-gateway-enable-wrap').each(function () {
            var $wrap = $(this);
            var $input = $wrap.find('[data-field="enabled"]');
            var $switcher = $wrap.find('.csf--switcher');
            if (!$input.length || !$switcher.length) {
                return;
            }
            $switcher.toggleClass('csf--active', $input.prop('checked'));
            $switcher.off('click.wyaGatewayEnable').on('click.wyaGatewayEnable', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var next = !$input.prop('checked');
                $input.prop('checked', next);
                $switcher.toggleClass('csf--active', next);
                syncEnabledUi($wrap.closest('.wya-gateway-card'));
                scheduleAutoSave();
            });
            $wrap.find('.csf--label').off('click.wyaGatewayEnable').on('click.wyaGatewayEnable', function (e) {
                e.preventDefault();
                $switcher.trigger('click');
            });
        });
    }

    function cardHtml(relay, index) {
        var status = relay.status || {};
        var statusClass = status.ok === true ? 'is-ok' : (status.ok === false ? 'is-error' : '');
        var mode = relay.mode === 'anthropic' ? 'anthropic' : 'openai';
        var enabledOn = isRelayEnabled(relay);
        var cardClass = 'wya-gateway-card wya-gateway-card--' + mode;
        if (enabledOn) {
            cardClass += ' wya-gateway-card--enabled';
        }
        var models = relay.models || [];
        var modelCount = models.length;
        var statusText = status.message || (enabledOn ? '等待测速' : '未启用');
        var keyBadge = relay.api_key_configured
            ? '<span class="wya-badge wya-badge--ok">密钥已保存</span>'
            : '<span class="wya-badge wya-badge--warn">未配置密钥</span>';

        var html = '<div class="' + cardClass + '" data-wya-gateway-index="' + index + '">';
        html += '<div class="wya-gateway-card__head">';
        html += '<div class="wya-gateway-card__brand">';
        html += '<span class="wya-gateway-card__icon"><i class="fa fa-cloud"></i></span>';
        html += '<div class="wya-gateway-card__text">';
        html += '<span class="wya-gateway-card__title">' + esc(relay.name || '未命名网关') + '</span>';
        html += '<span class="wya-gateway-card__meta">';
        html += '<code>' + esc(relay.key) + '</code>';
        html += modeBadge(mode);
        if (modelCount) {
            html += '<span>' + modelCount + ' 个模型</span>';
        }
        html += '</span></div></div>';
        html += '<div class="wya-gateway-card__head-actions">';
        html += '<div class="wya-gateway-enable-wrap">';
        html += '<div class="csf-field csf-field-switcher">';
        html += '<div class="csf-fieldset">';
        html += '<label>';
        html += '<input type="checkbox" data-field="enabled" value="1" class="csf--input"' + (enabledOn ? ' checked' : '') + '>';
        html += '<span class="csf--switcher' + (enabledOn ? ' csf--active' : '') + '"></span>';
        html += '<span class="csf--label">启用</span>';
        html += '</label></div></div>';
        if (enabledOn && !$.trim(relay.site_url || '')) {
            html += '<span class="wya-gateway-enable-hint">需填根地址</span>';
        }
        html += '</div>';
        html += keyBadge;
        if (status.message) {
            html += '<span class="wya-gateway-card__status ' + statusClass + '" title="' + esc(status.message) + '">' + esc(statusText) + '</span>';
        }
        if (index > 0 || state.relays.length > 1) {
            html += '<button type="button" class="button-link wya-gateway-card__remove" data-wya-gateway-remove>删除</button>';
        }
        html += '</div></div>';

        html += '<div class="wya-gateway-card__body">';
        html += '<div class="wya-gateway-card__grid">';
        html += '<p class="wya-gateway-field"><label>显示名称</label><input type="text" class="widefat" data-field="name" value="' + esc(relay.name) + '"></p>';
        html += '<p class="wya-gateway-field"><label>协议</label><select data-field="mode" class="widefat">';
        (state.modes || []).forEach(function (m) {
            html += '<option value="' + esc(m.value) + '"' + (relay.mode === m.value ? ' selected' : '') + '>' + esc(m.label) + '</option>';
        });
        html += '</select></p>';
        html += '<p class="wya-gateway-field wya-gateway-field--full"><label>网关根地址</label><input type="url" class="widefat" data-field="site_url" placeholder="https://api.example.com" value="' + esc(relay.site_url) + '"></p>';
        html += '<p class="wya-gateway-field wya-gateway-field--full"><label>API Key</label><input type="password" class="widefat" data-field="api_key" placeholder="' + esc(relay.api_key_configured ? '留空保持已保存密钥' : 'sk-...') + '" autocomplete="new-password" value=""></p>';
        html += '</div>';

        html += '<div class="wya-gateway-card__actions">';
        html += '<button type="button" class="button button-secondary button-small" data-wya-gateway-probe><i class="fa fa-tachometer"></i> 测速</button>';
        html += '<button type="button" class="button button-secondary button-small" data-wya-gateway-fetch><i class="fa fa-list"></i> 获取模型</button>';
        html += '</div>';

        html += '<div class="wya-gateway-models-wrap">';
        html += '<div class="wya-gateway-models-head">';
        html += '<p class="wya-gateway-models-head__title"><i class="fa fa-cubes"></i> 模型池</p>';
        if (modelCount) {
            html += '<span class="muted-3-color em09">共 ' + modelCount + ' 项</span>';
        }
        html += '</div>';

        if (modelCount) {
            html += '<table class="widefat wya-gateway-models"><thead><tr><th>模型 ID</th><th>能力</th></tr></thead><tbody>';
            models.forEach(function (model, mi) {
                html += '<tr data-model-index="' + mi + '"><td><code>' + esc(model.id) + '</code><br><span class="muted-3-color em09">' + esc(model.name) + '</span></td><td>';
                (state.capabilities || []).forEach(function (cap) {
                    var checked = (model.capabilities || []).indexOf(cap.value) >= 0 ? ' checked' : '';
                    html += '<label class="wya-gateway-cap"><input type="checkbox" data-cap="' + esc(cap.value) + '"' + checked + '><span>' + esc(cap.label) + '</span></label>';
                });
                html += '</td></tr>';
            });
            html += '</tbody></table>';
        } else {
            html += '<p class="wya-gateway-models-empty muted-3-color">尚未配置模型。请先填写地址与 API Key，点击「获取模型」后在此勾选文本 / 视觉 / 生图能力。</p>';
        }
        html += '</div></div></div>';

        return html;
    }

    function toolbarHtml() {
        var saveLabel = dirty ? '保存全部（有未保存更改）' : '保存全部';
        return '<div class="wya-gateway-toolbar">'
            + '<button type="button" class="button button-primary" data-wya-gateway-add><i class="fa fa-plus"></i> 添加网关</button>'
            + '<button type="button" class="button button-primary" data-wya-gateway-save><i class="fa fa-save"></i> ' + esc(saveLabel) + '</button>'
            + '<span class="wya-gateway-save-hint muted-3-color em09" data-wya-gateway-hint>填写后会自动保存；切换标签页前也可手动点保存</span>'
            + '</div>';
    }

    function render() {
        var $mount = $app();
        if (!$mount.length) {
            return;
        }

        var html = toolbarHtml();

        if (!state.relays.length) {
            html += '<div class="wya-gateway-empty"><i class="fa fa-cloud-upload"></i>暂无网关，点击「添加网关」开始配置 One API / New API 等中转站。</div>';
            $mount.html(html);
            return;
        }

        state.relays.forEach(function (relay, index) {
            html += cardHtml(relay, index);
        });

        $mount.html(html);
        initGatewaySwitchers($mount);
    }

    function collectRelays() {
        var relays = [];
        $app().find('.wya-gateway-card').each(function () {
            var $card = $(this);
            var index = parseInt($card.attr('data-wya-gateway-index'), 10);
            var base = state.relays[index] || {};
            var relay = $.extend(true, {}, base);
            relay.enabled = readCardEnabled($card);
            relay.name = $card.find('[data-field="name"]').val() || '';
            relay.site_url = $card.find('[data-field="site_url"]').val() || '';
            relay.mode = $card.find('[data-field="mode"]').val() || 'openai';
            var key = $.trim($card.find('[data-field="api_key"]').val() || '');
            if (key) {
                relay.api_key = key;
            } else {
                delete relay.api_key;
            }
            delete relay.api_key_configured;

            var models = [];
            $card.find('[data-model-index]').each(function () {
                var $row = $(this);
                var mi = parseInt($row.attr('data-model-index'), 10);
                var m = (base.models || [])[mi];
                if (!m) {
                    return;
                }
                var caps = [];
                $row.find('[data-cap]').each(function () {
                    if ($(this).is(':checked')) {
                        caps.push($(this).attr('data-cap'));
                    }
                });
                models.push({ id: m.id, name: m.name, capabilities: caps });
            });
            relay.models = models;
            relays.push(relay);
        });
        return relays;
    }

    function load() {
        var $mount = $app();
        if (!$mount.length || !cfgReady()) {
            return;
        }

        if (loading) {
            return;
        }
        loading = true;
        setStatus($mount, '正在加载网关配置…', false);

        api('/gateway', 'GET').done(function (res) {
            state.relays = (res && res.relays) ? res.relays : [];
            state.modes = (res && res.modes) ? res.modes : [];
            state.capabilities = (res && res.capabilities) ? res.capabilities : [];
            dirty = false;
            render();
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : '加载失败，请刷新页面重试。';
            setStatus($mount, esc(msg), true);
        }).always(function () {
            loading = false;
        });
    }

    function save(silent) {
        var $mount = $app();
        var $btn = $mount.find('[data-wya-gateway-save]');
        if ($btn.length) {
            $btn.prop('disabled', true);
        }

        return api('/gateway', 'POST', { relays: collectRelays() }).done(function (res) {
            state.relays = (res && res.relays) ? res.relays : collectRelays();
            dirty = false;
            render();
            if (!silent) {
                window.alert('网关配置已保存。');
            } else {
                $mount.find('[data-wya-gateway-hint]').text('已自动保存 ' + new Date().toLocaleTimeString());
            }
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : '保存失败';
            if (!silent) {
                window.alert(msg);
            } else {
                $mount.find('[data-wya-gateway-hint]').text('自动保存失败：' + msg);
            }
        }).always(function () {
            if ($btn.length) {
                $btn.prop('disabled', false);
            }
        });
    }

    function scheduleAutoSave() {
        dirty = true;
        var $hint = $app().find('[data-wya-gateway-hint]');
        if ($hint.length) {
            $hint.text('正在自动保存…');
        }
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(function () {
            save(true);
        }, 900);
    }

    function init() {
        if (!cfgReady() || !$app().length) {
            return;
        }
        load();
    }

    function isGatewaySectionVisible() {
        var $section = $('.wya-section-ai-gateway');
        return $section.length && $section.is(':visible');
    }

    $(document).ready(function () {
        init();

        $(document).on('csf_reload_script', function () {
            window.setTimeout(init, 80);
        });

        $(document).on('click', '.csf-nav a', function () {
            window.setTimeout(function () {
                if (isGatewaySectionVisible()) {
                    init();
                }
            }, 120);
        });
    });

    $(document).on('click', '#wya-gateway-app [data-wya-gateway-add]', function () {
        var n = state.relays.length + 1;
        state.relays.push({
            key: 'slot_' + n,
            enabled: false,
            name: '晚秋 AI 网关 ' + n,
            site_url: '',
            mode: 'openai',
            models: [],
            status: { latency: 0, ok: null, message: '', checked: '' },
            api_key_configured: false,
        });
        dirty = true;
        render();
        scheduleAutoSave();
    });

    $(document).on('click', '#wya-gateway-app [data-wya-gateway-remove]', function () {
        var index = parseInt($(this).closest('.wya-gateway-card').attr('data-wya-gateway-index'), 10);
        if (!window.confirm('确定删除此网关？')) {
            return;
        }
        state.relays.splice(index, 1);
        dirty = true;
        render();
        scheduleAutoSave();
    });

    $(document).on('click', '#wya-gateway-app [data-wya-gateway-save]', function () {
        save(false);
    });

    $(document).on('input change', '#wya-gateway-app [data-field]:not([data-field="enabled"]), #wya-gateway-app [data-cap]', function () {
        scheduleAutoSave();
    });

    $(document).on('change', '#wya-gateway-app [data-field="enabled"]', function () {
        syncEnabledUi($(this).closest('.wya-gateway-card'));
        scheduleAutoSave();
    });

    $(document).on('click', '#wya-gateway-app [data-wya-gateway-probe]', function () {
        var $card = $(this).closest('.wya-gateway-card');
        var index = parseInt($card.attr('data-wya-gateway-index'), 10);
        var relay = state.relays[index];
        if (!relay) {
            return;
        }
        save(true).done(function () {
            api('/gateway/' + encodeURIComponent(relay.key) + '/probe', 'POST').done(function (res) {
                if (state.relays[index]) {
                    state.relays[index].status = {
                        ok: !!res.ok,
                        message: res.message || '',
                        latency: res.latency || 0,
                        checked: new Date().toISOString(),
                    };
                    render();
                }
            });
        });
    });

    $(document).on('click', '#wya-gateway-app [data-wya-gateway-fetch]', function () {
        var $card = $(this).closest('.wya-gateway-card');
        var index = parseInt($card.attr('data-wya-gateway-index'), 10);
        var relay = state.relays[index];
        if (!relay) {
            return;
        }
        save(true).done(function () {
            api('/gateway/' + encodeURIComponent(relay.key) + '/fetch-models', 'POST').done(function (res) {
                if (!res.ok) {
                    window.alert(res.message || '获取失败');
                    return;
                }
                load();
            });
        });
    });

    window.addEventListener('beforeunload', function (e) {
        if (!dirty) {
            return;
        }
        e.preventDefault();
        e.returnValue = '';
    });
}(jQuery));
