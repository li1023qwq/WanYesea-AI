(function ($) {
    'use strict';

    var ADMIN = window.wanyeseaAiAdmin || {};
    var PROVIDERS = (ADMIN.providers && ADMIN.providers.length)
        ? ADMIN.providers
        : ['openai', 'google', 'anthropic'];

    function isSwitcherOn($input) {
        if (!$input || !$input.length) {
            return false;
        }

        var $switcher = $input.closest('.csf-field-switcher').find('.csf--switcher');
        if ($switcher.length && $switcher.hasClass('csf--active')) {
            return true;
        }

        var value = ($input.val() || '').toString().trim().toLowerCase();
        return value === '1' || value === 'true' || value === 'on' || value === 'yes';
    }

    function findSwitcherInput($section, fieldId) {
        var $field = $section.find('.csf-field').filter(function () {
            var $input = $(this).find('input[data-depend-id="' + fieldId + '"]');
            return $input.length > 0;
        }).first();

        if ($field.length) {
            return $field.find('input[data-depend-id="' + fieldId + '"]').first();
        }

        return $section.find('[data-depend-id="' + fieldId + '"]').first();
    }

    function getSection($root) {
        var $section = $root.closest('.wya-section-ai-connect');
        if ($section.length) {
            return $section;
        }
        return $('.csf.csf-options[data-slug="WanYesea_AI"] .wya-section-ai-connect').first();
    }

    function setRelayFieldVisible($field, visible) {
        $field.toggleClass('wya-field-hidden', !visible);
        $field.removeClass('csf-depend-on');
    }

    function setProviderVisible($section, providerId, visible) {
        setRelayFieldVisible($section.find('.wya-provider-' + providerId), visible);
    }

    function syncProviderCardLayout($section, providerId, masterOn, providerRelayOn) {
        var $blockFields = $section.find('.wya-provider-' + providerId + '.wya-provider-block-field');
        var $key = $blockFields.filter('.wya-provider-block-field--key');
        var $relay = $blockFields.filter('.wya-provider-block-field--relay');
        var $url = $blockFields.filter('.wya-provider-block-field--url');

        $key.css({
            'border-bottom': '',
            'border-radius': '',
            'margin-bottom': '',
            'padding-bottom': '',
        });
        $relay.css({
            'border-bottom': '',
            'border-radius': '',
            'margin-bottom': '',
            'padding-bottom': '',
        });
        $url.css({
            'border-bottom': '',
            'border-radius': '',
            'margin-bottom': '',
            'padding-bottom': '',
        });

        if (masterOn && !providerRelayOn) {
            $relay.css({
                'border-bottom': '1px solid var(--wya-border, rgba(0,0,0,.08))',
                'border-radius': '0 0 12px 12px',
                'margin-bottom': '0',
                'padding-bottom': '18px',
            });
            return;
        }

        if (masterOn && providerRelayOn) {
            $key.css({ 'border-bottom': 'none' });
            $url.css({
                'border-bottom': '1px solid var(--wya-border, rgba(0,0,0,.08))',
                'border-radius': '0 0 12px 12px',
                'margin-bottom': '0',
                'padding-bottom': '18px',
            });
        }
    }

    function syncRelayVisibility($root) {
        var $section = getSection($root);
        if (!$section.length) {
            return;
        }

        var masterOn = isSwitcherOn(findSwitcherInput($section, 'relay_enabled'));
        $section.toggleClass('wya-relay-master-on', masterOn);

        PROVIDERS.forEach(function (providerId) {
            var $providerNodes = $section.find('.wya-provider-' + providerId);
            var providerRelayOn = masterOn && isSwitcherOn(
                findSwitcherInput($section, 'relay_' + providerId + '_enabled')
            );
            var credentialsOn = masterOn && providerRelayOn;
            var $keyField = $section.find('.wya-provider-block-field--key.wya-provider-' + providerId);
            var $urlField = $section.find('.wya-provider-block-field--url.wya-provider-' + providerId);

            if (!masterOn) {
                $providerNodes.removeClass('wya-relay-provider-on');
                setProviderVisible($section, providerId, false);
                return;
            }

            setProviderVisible($section, providerId, true);
            $providerNodes.toggleClass('wya-relay-provider-on', providerRelayOn);

            setRelayFieldVisible($keyField, credentialsOn);
            setRelayFieldVisible($urlField, credentialsOn);

            syncProviderCardLayout($section, providerId, masterOn, providerRelayOn);
        });
    }

    function bindKeyToggle($root) {
        $root.find('.wya-key-toggle').off('click.wyaAi').on('click.wyaAi', function () {
            var providerId = $(this).data('wya-key-toggle');
            var $input = $root.find('[data-wya-key-input="' + providerId + '"]');
            if (!$input.length) {
                return;
            }
            var show = $input.attr('type') === 'password';
            $input.attr('type', show ? 'text' : 'password');
            $(this).toggleClass('is-visible', show).html(
                show ? '<i class="fa fa-eye-slash"></i> 隐藏' : '<i class="fa fa-eye"></i> 显示'
            );
        });
    }

    function getProbeI18n(key, fallback) {
        if (ADMIN.i18n && ADMIN.i18n[key]) {
            return ADMIN.i18n[key];
        }
        return fallback;
    }

    function collectProviderProbePayload($section, providerId) {
        var payload = {
            action: 'wanyesea_ai_probe_provider',
            nonce: ADMIN.probeNonce || '',
            provider_id: providerId,
        };

        var $keyInput = $section.find('[data-wya-key-input="' + providerId + '"]');
        if ($keyInput.length) {
            var keyVal = ($keyInput.val() || '').toString().trim();
            if (keyVal !== '') {
                payload.api_key = keyVal;
            }
        }

        var masterOn = isSwitcherOn(findSwitcherInput($section, 'relay_enabled'));
        var relayOn = masterOn && isSwitcherOn(
            findSwitcherInput($section, 'relay_' + providerId + '_enabled')
        );
        payload.relay_enabled = relayOn ? '1' : '0';

        if (relayOn) {
            var $urlInput = $section.find('[data-depend-id="relay_' + providerId + '_base_url"]');
            if ($urlInput.length) {
                payload.relay_base_url = ($urlInput.val() || '').toString().trim();
            }
        }

        return payload;
    }

    function formatModelList(models, limit) {
        if (!models || !models.length) {
            return '';
        }
        var max = limit || 12;
        var shown = models.slice(0, max);
        var text = shown.map(function (id) {
            return '<code>' + $('<div>').text(id).html() + '</code>';
        }).join(' ');
        if (models.length > max) {
            text += ' <span class="muted-3-color em09">等 ' + models.length + ' 个</span>';
        }
        return text;
    }

    function renderProbeDetail(data) {
        var html = '<div class="wya-env-probe-detail__inner">';
        html += '<p class="wya-env-probe-detail__msg">' + $('<div>').text(data.message || '').html() + '</p>';

        if (data.endpoint_url) {
            html += '<p class="em09 muted-3-color">';
            html += getProbeI18n('endpoint', '端点') + '：';
            html += '<code>' + $('<div>').text(data.endpoint_url).html() + '</code>';
            if (data.endpoint_mode === 'relay') {
                html += ' <span class="wya-badge wya-badge--relay">中转</span>';
            } else if (data.endpoint_mode === 'official') {
                html += ' <span class="wya-badge wya-badge--ok">官方</span>';
            }
            html += '</p>';
        }

        if (data.latency_ms) {
            html += '<p class="em09 muted-3-color">' + getProbeI18n('latency', '耗时') + '：' + data.latency_ms + ' ms';
            if (data.http_code) {
                html += ' · ' + getProbeI18n('httpCode', 'HTTP') + ' ' + data.http_code;
            }
            html += '</p>';
        }

        if (data.registry_hint) {
            html += '<p class="em09 muted-3-color">' + $('<div>').text(data.registry_hint).html() + '</p>';
        }

        if (data.models) {
            if (data.models.text && data.models.text.length) {
                html += '<p class="wya-env-probe-detail__models"><strong>' + getProbeI18n('textModels', '文本模型') + '：</strong> ';
                html += formatModelList(data.models.text) + '</p>';
            }
            if (data.models.image && data.models.image.length) {
                html += '<p class="wya-env-probe-detail__models"><strong>' + getProbeI18n('imageModels', '图像模型') + '：</strong> ';
                html += formatModelList(data.models.image) + '</p>';
            }
            if (data.models.other && data.models.other.length) {
                html += '<p class="wya-env-probe-detail__models"><strong>' + getProbeI18n('otherModels', '其它模型') + '：</strong> ';
                html += formatModelList(data.models.other, 8) + '</p>';
            }
        }

        html += '</div>';
        return html;
    }

    function setProbeRowState($row, state, statusText, detailHtml) {
        $row.removeClass('is-ok is-warn is-idle is-loading');
        if (state) {
            $row.addClass(state);
        }
        if (statusText) {
            $row.find('[data-wya-probe-status]').text(statusText);
        }
        var $detail = $row.find('[data-wya-probe-detail]');
        if (detailHtml) {
            $detail.html(detailHtml).prop('hidden', false);
        } else {
            $detail.empty().prop('hidden', true);
        }
    }

    function setInlineProbeResult($section, providerId, state, html) {
        var $inline = $section.find('[data-wya-probe-inline="' + providerId + '"]');
        if (!$inline.length) {
            return;
        }
        if (html) {
            $inline.removeClass('is-ok is-warn').addClass(state || '').html(html).prop('hidden', false);
        } else {
            $inline.empty().prop('hidden', true);
        }
    }

    function runProviderProbe($section, providerId, $trigger) {
        if (!ADMIN.ajaxUrl || !ADMIN.probeNonce) {
            return;
        }

        var $row = $section.find('[data-wya-probe-provider="' + providerId + '"]');
        var $btn = $trigger && $trigger.length ? $trigger : $section.find('[data-wya-probe-run="' + providerId + '"]').first();
        var probingLabel = getProbeI18n('probing', '检测中…');
        var probeLabel = '<i class="fa fa-refresh"></i> ' + getProbeI18n('probe', '检测');
        var restoreLabel = $btn.data('wya-probe-in-card')
            ? '<i class="fa fa-refresh"></i> 检测连通'
            : probeLabel;

        $btn.prop('disabled', true);
        if ($row.length) {
            setProbeRowState($row, 'is-loading', probingLabel, '');
        }

        $.post(ADMIN.ajaxUrl, collectProviderProbePayload($section, providerId))
            .done(function (res) {
                if (!res || !res.success || !res.data) {
                    var msg = (res && res.data && res.data.message) ? res.data.message : getProbeI18n('networkError', '请求失败');
                    if ($row.length) {
                        setProbeRowState($row, 'is-warn', msg, '');
                    }
                    setInlineProbeResult($section, providerId, 'is-warn', '<p class="wya-provider-probe-inline__msg">' + $('<div>').text(msg).html() + '</p>');
                    return;
                }

                var data = res.data;
                var state = data.reachable ? 'is-ok' : 'is-warn';
                var detail = renderProbeDetail(data);

                if ($row.length) {
                    setProbeRowState($row, state, data.message || '', detail);
                }
                setInlineProbeResult($section, providerId, state, '<div class="wya-provider-probe-inline__box">' + detail + '</div>');
            })
            .fail(function () {
                var msg = getProbeI18n('networkError', '请求失败，请稍后重试');
                if ($row.length) {
                    setProbeRowState($row, 'is-warn', msg, '');
                }
                setInlineProbeResult($section, providerId, 'is-warn', '<p class="wya-provider-probe-inline__msg">' + msg + '</p>');
            })
            .always(function () {
                $btn.prop('disabled', false).html(restoreLabel);
            });
    }

    function runAllProviderProbes($section, $btn) {
        if (!ADMIN.ajaxUrl || !ADMIN.probeNonce) {
            return;
        }

        var label = getProbeI18n('probeAll', '全部检测');
        $btn.prop('disabled', true).text(getProbeI18n('probing', '检测中…'));

        $.post(ADMIN.ajaxUrl, {
            action: 'wanyesea_ai_probe_all_providers',
            nonce: ADMIN.probeNonce,
        })
            .done(function (res) {
                if (!res || !res.success || !res.data || !res.data.providers) {
                    return;
                }
                Object.keys(res.data.providers).forEach(function (providerId) {
                    var data = res.data.providers[providerId];
                    var $row = $section.find('[data-wya-probe-provider="' + providerId + '"]');
                    if (!$row.length) {
                        return;
                    }
                    var state = data.reachable ? 'is-ok' : 'is-warn';
                    setProbeRowState($row, state, data.message || '', renderProbeDetail(data));
                });
            })
            .always(function () {
                $btn.prop('disabled', false).html('<i class="fa fa-refresh"></i> ' + label);
            });
    }

    function bindEndpointProbe($section) {
        $section.off('click.wyaProbe');

        $section.on('click.wyaProbe', '[data-wya-probe-run]', function (e) {
            e.preventDefault();
            var providerId = $(this).data('wya-probe-run');
            if (!providerId) {
                return;
            }
            runProviderProbe($section, providerId, $(this));
        });

        $section.on('click.wyaProbe', '[data-wya-probe-run-all]', function (e) {
            e.preventDefault();
            runAllProviderProbes($section, $(this));
        });
    }

    function initAiConnect($form) {
        var $section = $form.find('.wya-section-ai-connect');
        if (!$section.length) {
            return;
        }

        syncRelayVisibility($form);
        bindKeyToggle($form);
        bindEndpointProbe($section);

        $form.off('.wyaAiRelay');

        $form.on(
            'change.wyaAiRelay input.wyaAiRelay',
            '.wya-section-ai-connect input[data-depend-id^="relay_"]',
            function () {
                syncRelayVisibility($form);
            }
        );

        $form.on('click.wyaAiRelay', '.wya-section-ai-connect .csf--switcher', function () {
            window.setTimeout(function () {
                syncRelayVisibility($form);
            }, 0);
        });
    }

    function mountEnvGrid() {
        var $mount = $('#wya-connect-env-grid');
        if (!$mount.length || !ADMIN.envGridHtml) {
            return;
        }
        $mount.html(ADMIN.envGridHtml);
    }

    function boot() {
        var $form = $('.csf.csf-options[data-slug="WanYesea_AI"]');
        if (!$form.length) {
            return;
        }
        mountEnvGrid();
        initAiConnect($form);
        window.setTimeout(function () {
            syncRelayVisibility($form);
        }, 50);
        window.setTimeout(function () {
            syncRelayVisibility($form);
        }, 300);
    }

    $(document).ready(boot);
    $(document).on('csf_reload_script', boot);
})(jQuery);
