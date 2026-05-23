(function () {
	'use strict';

	var cfg = window.wanyeseaAiPostDraft;
	if (!cfg) {
		return;
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = text;
		}
		return node;
	}

	function injectButton() {
		var heading = document.querySelector('.wrap > h1.wp-heading-inline');
		if (!heading || document.getElementById('wanyesea-ai-post-draft-btn')) {
			return;
		}

		var btn = el('a', 'page-title-action');
		btn.id = 'wanyesea-ai-post-draft-btn';
		btn.href = '#';
		btn.textContent = cfg.i18n.button;
		btn.addEventListener('click', function (event) {
			event.preventDefault();
			openModal();
		});

		var addNew = heading.parentNode.querySelector('a.page-title-action');
		if (addNew && addNew.nextSibling) {
			addNew.parentNode.insertBefore(btn, addNew.nextSibling);
		} else {
			heading.insertAdjacentElement('afterend', btn);
		}
	}

	var modalRoot = null;
	var pollTimer = null;
	var isSubmitting = false;

	function closeModal() {
		if (pollTimer) {
			window.clearInterval(pollTimer);
			pollTimer = null;
		}
		isSubmitting = false;
		if (modalRoot) {
			modalRoot.remove();
			modalRoot = null;
		}
		document.body.classList.remove('wanyesea-ai-post-draft-modal-open');
	}

	function showMessage(box, text, type) {
		box.textContent = text;
		box.className = 'wanyesea-ai-post-draft-message' + (type ? ' is-' + type : '');
		box.hidden = !text;
	}

	var apiFetchReady = false;

	function ensureApiFetch() {
		if (apiFetchReady || !window.wp || !window.wp.apiFetch) {
			return;
		}
		window.wp.apiFetch.use(window.wp.apiFetch.createNonceMiddleware(cfg.restNonce));
		window.wp.apiFetch.use(
			window.wp.apiFetch.createRootURLMiddleware(cfg.restRoot)
		);
		apiFetchReady = true;
	}

	function apiFetch(path, options) {
		ensureApiFetch();
		if (!window.wp || !window.wp.apiFetch) {
			return Promise.reject(new Error('wp.apiFetch unavailable'));
		}

		return window.wp.apiFetch(
			Object.assign(
				{
					path: path,
				},
				options || {}
			)
		);
	}

	function formatBatchMessage(template, done, total) {
		return String(template || '')
			.replace('%d', String(done))
			.replace('%d', String(total));
	}

	function createRequestId() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		return 'wya-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
	}

	function pollStatus(postIds, messageBox) {
		var ids = Array.isArray(postIds) ? postIds : [postIds];
		var total = ids.length;

		pollTimer = window.setInterval(function () {
			Promise.all(
				ids.map(function (id) {
					return apiFetch('/wanyesea-ai/v1/post-drafts/' + id, {
						method: 'GET',
					});
				})
			)
				.then(function (results) {
					var done = 0;
					var failed = 0;
					var errors = [];

					results.forEach(function (data) {
						if (!data || !data.status) {
							return;
						}
						if (data.status === 'complete') {
							done++;
						} else if (data.status === 'failed') {
							failed++;
							if (data.error) {
								errors.push(data.error);
							}
						}
					});

					if (done + failed < total) {
						if (total > 1) {
							showMessage(
								messageBox,
								formatBatchMessage(cfg.i18n.batchProgress, done, total),
								'info'
							);
						}
						return;
					}

					window.clearInterval(pollTimer);
					pollTimer = null;

					if (failed > 0) {
						var failMsg =
							done +
							'/' +
							total +
							' 篇成功，' +
							failed +
							' 篇失败';
						if (errors[0]) {
							failMsg += '：' + errors[0];
						}
						showMessage(messageBox, failMsg, failed === total ? 'error' : 'info');
					} else if (total > 1) {
						showMessage(
							messageBox,
							formatBatchMessage(cfg.i18n.batchComplete, done, total),
							'success'
						);
					} else {
						showMessage(
							messageBox,
							'生成完成：《' + (results[0].title || '') + '》',
							'success'
						);
					}

					window.setTimeout(function () {
						window.location.reload();
					}, 1200);
				})
				.catch(function () {
					/* 轮询失败时静默 */
				});
		}, 4000);
	}

	function fillModelSelect(select, models, selectedId) {
		while (select.firstChild) {
			select.removeChild(select.firstChild);
		}

		var placeholder = el('option', '', cfg.i18n.modelPlaceholder);
		placeholder.value = '';
		select.appendChild(placeholder);

		(models || []).forEach(function (modelId) {
			var opt = el('option', '', modelId);
			opt.value = modelId;
			if (selectedId && modelId === selectedId) {
				opt.selected = true;
			}
			select.appendChild(opt);
		});

		if (selectedId && models && models.indexOf(selectedId) === -1) {
			var customOpt = el('option', '', selectedId);
			customOpt.value = selectedId;
			customOpt.selected = true;
			select.appendChild(customOpt);
		}
	}

	function getProviderDefaultModel(providerId) {
		var providers = cfg.providers || [];
		for (var i = 0; i < providers.length; i++) {
			if (providers[i].id === providerId) {
				return providers[i].default_model || '';
			}
		}
		return '';
	}

	function loadModels(providerId, refresh, modelSelect, modelCustom, loadBtn) {
		if (!providerId) {
			fillModelSelect(modelSelect, [], '');
			return Promise.resolve([]);
		}

		if (loadBtn) {
			loadBtn.disabled = true;
			loadBtn.textContent = cfg.i18n.loadingModels;
		}

		var path =
			'/wanyesea-ai/v1/post-drafts/models?provider_id=' +
			encodeURIComponent(providerId);
		if (refresh) {
			path += '&refresh=1';
		}

		return apiFetch(path, { method: 'GET' })
			.then(function (data) {
				var models = (data && data.models) || [];
				var selected =
					modelCustom && modelCustom.value.trim()
						? modelCustom.value.trim()
						: modelSelect.value || getProviderDefaultModel(providerId);
				fillModelSelect(modelSelect, models, selected);
				if (selected && modelCustom && !modelCustom.value.trim()) {
					modelSelect.value = selected;
				}
				return models;
			})
			.catch(function () {
				var hint = getProviderDefaultModel(providerId);
				fillModelSelect(modelSelect, hint ? [hint] : [], hint);
				return [];
			})
			.finally(function () {
				if (loadBtn) {
					loadBtn.disabled = false;
					loadBtn.textContent = cfg.i18n.loadModels;
				}
			});
	}

	function getSelectedModelId(modelSelect, modelCustom) {
		var custom = modelCustom ? modelCustom.value.trim() : '';
		if (custom !== '') {
			return custom;
		}
		return modelSelect ? modelSelect.value.trim() : '';
	}

	function buildProviderModelFields(body) {
		var providers = cfg.providers || [];
		var wrap = el('div', 'wanyesea-ai-post-draft-model-fields');

		wrap.appendChild(el('label', '', cfg.i18n.providerLabel));
		var providerSelect = el('select', 'widefat');
		providerSelect.id = 'wanyesea-ai-post-draft-provider';

		providers.forEach(function (p) {
			var opt = el('option', '', p.label);
			opt.value = p.id;
			providerSelect.appendChild(opt);
		});

		if (cfg.default && cfg.default.provider_id) {
			providerSelect.value = cfg.default.provider_id;
		} else if (providers.length > 0) {
			providerSelect.value = providers[0].id;
		}

		wrap.appendChild(providerSelect);

		wrap.appendChild(el('label', '', cfg.i18n.modelLabel));
		var modelRow = el('div', 'wanyesea-ai-post-draft-model-row');
		var modelSelect = el('select', 'widefat');
		modelSelect.id = 'wanyesea-ai-post-draft-model';
		modelRow.appendChild(modelSelect);

		var loadBtn = el('button', 'button', cfg.i18n.loadModels);
		loadBtn.type = 'button';
		modelRow.appendChild(loadBtn);
		wrap.appendChild(modelRow);

		wrap.appendChild(el('label', '', cfg.i18n.modelCustomLabel));
		var modelCustom = el('input', 'widefat');
		modelCustom.type = 'text';
		modelCustom.id = 'wanyesea-ai-post-draft-model-custom';
		modelCustom.placeholder = '例如：gpt-4o、deepseek-chat、64/gpt-4o';
		wrap.appendChild(modelCustom);

		providerSelect.addEventListener('change', function () {
			modelCustom.value = '';
			loadModels(providerSelect.value, false, modelSelect, modelCustom, loadBtn);
		});

		loadBtn.addEventListener('click', function () {
			loadModels(providerSelect.value, true, modelSelect, modelCustom, loadBtn);
		});

		modelCustom.addEventListener('input', function () {
			if (modelCustom.value.trim() !== '') {
				modelSelect.value = '';
			}
		});

		modelSelect.addEventListener('change', function () {
			if (modelSelect.value !== '') {
				modelCustom.value = '';
			}
		});

		body.appendChild(wrap);

		var initialProvider = providerSelect.value;
		var initialModel =
			cfg.default && cfg.default.provider_id === initialProvider
				? cfg.default.model_id
				: getProviderDefaultModel(initialProvider);
		if (initialModel) {
			modelSelect.dataset.pending = initialModel;
		}

		loadModels(initialProvider, false, modelSelect, modelCustom, loadBtn).then(
			function () {
				if (modelSelect.dataset.pending) {
					modelSelect.value = modelSelect.dataset.pending;
					delete modelSelect.dataset.pending;
				}
			}
		);

		return {
			providerSelect: providerSelect,
			modelSelect: modelSelect,
			modelCustom: modelCustom,
		};
	}

	function openModal() {
		closeModal();
		document.body.classList.add('wanyesea-ai-post-draft-modal-open');

		modalRoot = el('div', 'wanyesea-ai-post-draft-overlay');
		var dialog = el('div', 'wanyesea-ai-post-draft-dialog');
		var header = el('div', 'wanyesea-ai-post-draft-header');
		header.appendChild(el('h2', '', cfg.i18n.title));
		var closeBtn = el('button', 'wanyesea-ai-post-draft-close', '×');
		closeBtn.type = 'button';
		closeBtn.addEventListener('click', closeModal);
		header.appendChild(closeBtn);

		var body = el('div', 'wanyesea-ai-post-draft-body');
		var messageBox = el('div', 'wanyesea-ai-post-draft-message');
		messageBox.hidden = true;
		body.appendChild(messageBox);

		var modelFields = null;

		if (!cfg.hasModel || !cfg.providers || cfg.providers.length === 0) {
			showMessage(messageBox, cfg.i18n.noModel, 'error');
			var settingsLink = el('a', 'button button-primary');
			settingsLink.href = cfg.settingsUrl;
			settingsLink.textContent = cfg.i18n.openSettings;
			settingsLink.target = '_blank';
			body.appendChild(settingsLink);
		} else {
			modelFields = buildProviderModelFields(body);

			body.appendChild(el('label', '', cfg.i18n.countLabel));
			var countRow = el('div', 'wanyesea-ai-post-draft-count-row');
			var countInput = el('input', 'small-text');
			countInput.type = 'number';
			countInput.min = '1';
			countInput.max = String(cfg.maxCount || 5);
			countInput.step = '1';
			countInput.value = String(cfg.defaultCount || 1);
			countRow.appendChild(countInput);
			countRow.appendChild(
				el('span', 'wanyesea-ai-post-draft-count-hint', '篇（最多 ' + (cfg.maxCount || 5) + ' 篇）')
			);
			body.appendChild(countRow);
			body.appendChild(el('p', 'wanyesea-ai-post-draft-help', cfg.i18n.countHelp));

			body.appendChild(el('label', '', cfg.i18n.promptLabel));
			var prompt = el('textarea', 'widefat');
			prompt.rows = 5;
			prompt.placeholder = cfg.i18n.promptPlaceholder;
			body.appendChild(prompt);

			body.appendChild(el('label', '', cfg.i18n.keywordsLabel));
			var keywords = el('textarea', 'widefat');
			keywords.rows = 2;
			keywords.placeholder = cfg.i18n.keywordsPlaceholder;
			body.appendChild(keywords);

			var actions = el('div', 'wanyesea-ai-post-draft-actions');
			var submit = el('button', 'button button-primary', cfg.i18n.submit);
			submit.type = 'button';
			var cancel = el('button', 'button', cfg.i18n.cancel);
			cancel.type = 'button';
			cancel.addEventListener('click', closeModal);

			submit.addEventListener('click', function () {
				if (isSubmitting) {
					return;
				}

				var promptVal = prompt.value.trim();
				var keywordsVal = keywords.value.trim();
				var providerId = modelFields.providerSelect.value;
				var modelId = getSelectedModelId(
					modelFields.modelSelect,
					modelFields.modelCustom
				);
				var countVal = parseInt(countInput.value, 10);
				if (isNaN(countVal) || countVal < 1) {
					countVal = 1;
				}
				if (countVal > (cfg.maxCount || 5)) {
					countVal = cfg.maxCount || 5;
				}

				if (!providerId) {
					showMessage(messageBox, cfg.i18n.pickProvider, 'error');
					return;
				}
				if (!modelId) {
					showMessage(messageBox, cfg.i18n.pickModel, 'error');
					return;
				}
				if (!promptVal && !keywordsVal) {
					showMessage(messageBox, cfg.i18n.emptyInput, 'error');
					return;
				}

				isSubmitting = true;
				submit.disabled = true;
				cancel.disabled = true;
				showMessage(messageBox, cfg.i18n.submitting, 'info');

				var requestId = createRequestId();

				apiFetch('/wanyesea-ai/v1/post-drafts', {
					method: 'POST',
					data: {
						prompt: promptVal,
						keywords: keywordsVal,
						post_type: cfg.postType,
						provider_id: providerId,
						model_id: modelId,
						count: countVal,
						request_id: requestId,
					},
				})
					.then(function (data) {
						var msg =
							data && data.message
								? data.message
								: countVal > 1
									? formatBatchMessage(cfg.i18n.submittedBatch, countVal, countVal)
									: cfg.i18n.submitted;
						showMessage(messageBox, msg, 'success');
						submit.textContent = cfg.i18n.close;
						submit.disabled = false;
						cancel.hidden = true;
						submit.onclick = closeModal;

						var ids =
							data && data.post_ids && data.post_ids.length
								? data.post_ids
								: data && data.post_id
									? [data.post_id]
									: [];
						if (ids.length) {
							pollStatus(ids, messageBox);
						}
					})
					.catch(function (err) {
						isSubmitting = false;
						var msg =
							(err && err.message) ||
							(err && err.data && err.data.message) ||
							cfg.i18n.errorGeneric;
						showMessage(messageBox, msg, 'error');
						submit.disabled = false;
						cancel.disabled = false;
					});
			});

			actions.appendChild(submit);
			actions.appendChild(cancel);
			body.appendChild(actions);
		}

		dialog.appendChild(header);
		dialog.appendChild(body);
		modalRoot.appendChild(dialog);
		modalRoot.addEventListener('click', function (event) {
			if (event.target === modalRoot) {
				closeModal();
			}
		});
		document.body.appendChild(modalRoot);
	}

	function bindRetryLinks() {
		document.addEventListener('click', function (event) {
			var target = event.target;
			if (!target || !target.classList.contains('wanyesea-ai-retry-draft')) {
				return;
			}
			event.preventDefault();
			var postId = target.getAttribute('data-post-id');
			if (!postId) {
				return;
			}
			target.textContent = cfg.i18n.retrying || '重试中…';
			apiFetch('/wanyesea-ai/v1/post-drafts/' + postId + '/retry', {
				method: 'POST',
			})
				.then(function () {
					window.location.reload();
				})
				.catch(function () {
					target.textContent = cfg.i18n.retry || '重试 AI';
					window.alert(cfg.i18n.errorGeneric);
				});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			injectButton();
			bindRetryLinks();
		});
	} else {
		injectButton();
		bindRetryLinks();
	}
})();
