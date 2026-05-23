(function ($) {
    'use strict';

    var LAB = window.wanyeseaAiTestLab || {};
    var I18N = LAB.i18n || {};

    function t(key, fallback) {
        return I18N[key] || fallback;
    }

    function sectionFrom($el) {
        var section = $el.closest('.wya-test-card').data('wya-test-section');
        return section === 'image' ? 'image' : 'text';
    }

    function $card(providerId, section) {
        var $cards = $('.wya-test-card[data-wya-test-provider="' + providerId + '"]');
        if (section) {
            $cards = $cards.filter('[data-wya-test-section="' + section + '"]');
        }
        return $cards.first();
    }

    function $result(providerId, section) {
        return $card(providerId, section).find('[data-wya-test-result="' + providerId + '"]');
    }

    function showResult(providerId, section, html, ok) {
        var $box = $result(providerId, section);
        $box.removeClass('is-ok is-error').addClass(ok ? 'is-ok' : 'is-error');
        $box.html(html).prop('hidden', false);
    }

    function runProbe(providerId, $btn, section) {
        if (!LAB.ajaxUrl || !LAB.probeNonce) {
            return;
        }

        var label = '<i class="fa fa-refresh"></i> ' + t('probe', '检测端点');
        $btn.prop('disabled', true).text(t('working', '执行中…'));

        $.post(LAB.ajaxUrl, {
            action: 'wanyesea_ai_probe_provider',
            nonce: LAB.probeNonce,
            provider_id: providerId,
        })
            .done(function (res) {
                if (!res || !res.success || !res.data) {
                    showResult(providerId, section, esc(res && res.data && res.data.message ? res.data.message : t('networkError', '请求失败')), false);
                    return;
                }

                var d = res.data;
                var html = '<p><strong>' + esc(d.message || '') + '</strong></p>';
                if (d.latency_ms) {
                    html += '<p class="description">耗时 ' + d.latency_ms + ' ms</p>';
                }
                if (d.models) {
                    var $sel = $card(providerId, section).find('[data-wya-test-model="' + providerId + '"]');
                    var current = $sel.val();
                    var kinds = section === 'image' ? ['image'] : ['text', 'other'];
                    $sel.find('option:not(:first)').remove();
                    kinds.forEach(function (kind) {
                        if (!d.models[kind] || !d.models[kind].length) {
                            return;
                        }
                        d.models[kind].forEach(function (id) {
                            $sel.append($('<option></option>').val(id).text(id));
                        });
                    });
                    if (current) {
                        $sel.val(current);
                    }
                }
                showResult(providerId, section, html, !!d.reachable);
            })
            .fail(function () {
                showResult(providerId, section, t('networkError', '请求失败'), false);
            })
            .always(function () {
                $btn.prop('disabled', false).html(label);
            });
    }

    function loadModels(providerId, capability, $btn, section) {
        $btn.prop('disabled', true).text(t('working', '执行中…'));

        $.post(LAB.ajaxUrl, {
            action: 'wanyesea_ai_test_lab_models',
            nonce: LAB.nonce,
            provider_id: providerId,
            capability: capability,
        })
            .done(function (res) {
                if (!res || !res.success || !res.data) {
                    showResult(providerId, section, t('networkError', '请求失败'), false);
                    return;
                }

                var $sel = $card(providerId, section).find('[data-wya-test-model="' + providerId + '"]');
                var current = $sel.val();
                $sel.find('option:not(:first)').remove();
                (res.data.models || []).forEach(function (id) {
                    $sel.append($('<option></option>').val(id).text(id));
                });
                if (current) {
                    $sel.val(current);
                }

                showResult(
                    providerId,
                    section,
                    '<p>已加载 <strong>' + (res.data.models || []).length + '</strong> 个'
                        + (capability === 'image' ? '图像' : '文本') + '模型。</p>',
                    (res.data.models || []).length > 0
                );
            })
            .fail(function () {
                showResult(providerId, section, t('networkError', '请求失败'), false);
            })
            .always(function () {
                $btn.prop('disabled', false).text(t('loadModels', '加载模型'));
            });
    }

    function runTextTest(providerId, $btn, section) {
        var $scope = $card(providerId, section);
        var $sel = $scope.find('[data-wya-test-model="' + providerId + '"]');
        var modelId = ($sel.val() || '').toString();
        if (!modelId) {
            showResult(providerId, section, t('pickModel', '请先选择模型'), false);
            return;
        }

        var prompt = $scope.find('[data-wya-test-prompt-text="' + providerId + '"]').val() || LAB.defaults.textPrompt;
        var restore = $btn.html();
        $btn.prop('disabled', true).text(t('working', '执行中…'));

        $.post(LAB.ajaxUrl, {
            action: 'wanyesea_ai_test_lab_text',
            nonce: LAB.nonce,
            provider_id: providerId,
            model_id: modelId,
            prompt: prompt,
        })
            .done(function (res) {
                if (!res || !res.success || !res.data) {
                    showResult(providerId, section, esc(res && res.data && res.data.message ? res.data.message : t('networkError', '请求失败')), false);
                    return;
                }

                var html = '<p><strong>生成成功</strong>（' + esc(res.data.model_id) + '，' + res.data.latency_ms + ' ms）</p>';
                if (res.data.gliner) {
                    var g = res.data.gliner;
                    html += '<p><strong>标注文本</strong></p><pre>' + esc(g.tagged_text || '') + '</pre>';
                    html += '<p><strong>实体</strong>（共 ' + (g.total_entities || 0) + ' 个）</p>';
                    if (g.entities && g.entities.length) {
                        html += '<ul class="wya-gliner-entities">';
                        g.entities.forEach(function (ent) {
                            html += '<li><code>' + esc(ent.label || '') + '</code> '
                                + esc(ent.text || '')
                                + ' <span class="description">'
                                + esc(String(ent.start != null ? ent.start : ''))
                                + '–'
                                + esc(String(ent.end != null ? ent.end : ''))
                                + (ent.score != null ? ' · ' + ent.score : '')
                                + '</span></li>';
                        });
                        html += '</ul>';
                    } else {
                        html += '<p class="description">未检测到实体。</p>';
                    }
                }
                html += '<pre>' + esc(res.data.text) + '</pre>';
                showResult(providerId, section, html, true);
            })
            .fail(function (xhr) {
                var msg = t('networkError', '请求失败');
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                showResult(providerId, section, esc(msg), false);
            })
            .always(function () {
                $btn.prop('disabled', false).html(restore);
            });
    }

    function runImageTest(providerId, $btn, section) {
        var $scope = $card(providerId, section);
        var $sel = $scope.find('[data-wya-test-model="' + providerId + '"]');
        var modelId = ($sel.val() || '').toString();
        if (!modelId) {
            showResult(providerId, section, t('pickModel', '请先选择模型'), false);
            return;
        }

        var prompt = $scope.find('[data-wya-test-prompt-image="' + providerId + '"]').val() || LAB.defaults.imagePrompt;
        var restore = $btn.html();
        $btn.prop('disabled', true).text(t('working', '执行中…'));
        showResult(providerId, section, '<p>图像生成中，请稍候（最长约 3 分钟）…</p>', true);

        $.post(LAB.ajaxUrl, {
            action: 'wanyesea_ai_test_lab_image',
            nonce: LAB.nonce,
            provider_id: providerId,
            model_id: modelId,
            prompt: prompt,
        })
            .done(function (res) {
                if (!res || !res.success || !res.data) {
                    showResult(providerId, section, esc(res && res.data && res.data.message ? res.data.message : t('networkError', '请求失败')), false);
                    return;
                }

                var html = '<p><strong>出图成功</strong>（' + esc(res.data.model_id) + '，' + res.data.latency_ms + ' ms）</p>';
                if (res.data.data_url) {
                    html += '<img src="' + escAttr(res.data.data_url) + '" alt="generated" />';
                }
                showResult(providerId, section, html, true);
            })
            .fail(function (xhr) {
                var msg = t('networkError', '请求失败');
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                showResult(providerId, section, esc(msg), false);
            })
            .always(function () {
                $btn.prop('disabled', false).html(restore);
            });
    }

    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function escAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    $(document).on('click', '[data-wya-test-probe]', function () {
        var $btn = $(this);
        runProbe($btn.data('wya-test-probe'), $btn, sectionFrom($btn));
    });

    $(document).on('click', '[data-wya-test-load-models]', function () {
        var $btn = $(this);
        loadModels(
            $btn.data('wya-test-load-models'),
            $btn.data('wya-test-cap') || sectionFrom($btn),
            $btn,
            sectionFrom($btn)
        );
    });

    $(document).on('click', '[data-wya-test-run-text]', function () {
        var $btn = $(this);
        runTextTest($btn.data('wya-test-run-text'), $btn, sectionFrom($btn));
    });

    $(document).on('click', '[data-wya-test-run-image]', function () {
        var $btn = $(this);
        runImageTest($btn.data('wya-test-run-image'), $btn, sectionFrom($btn));
    });
})(jQuery);
