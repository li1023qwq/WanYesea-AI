(function () {
	'use strict';

	var cfg = window.wanyeseaAiCommentReply;
	if (!cfg) {
		return;
	}

	var modalRoot = null;
	var apiFetchReady = false;

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
			Object.assign({ path: path }, options || {})
		);
	}

	function showMessage(box, text, type) {
		box.textContent = text;
		box.className =
			'wanyesea-ai-comment-reply-message' + (type ? ' is-' + type : '');
		box.hidden = !text;
	}

	function closeModal() {
		if (modalRoot) {
			modalRoot.remove();
			modalRoot = null;
		}
		document.body.classList.remove('wanyesea-ai-comment-reply-modal-open');
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
			'/wanyesea-ai/v1/comment-replies/models?provider_id=' +
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
		var wrap = el('div', 'wanyesea-ai-comment-reply-model-fields');

		wrap.appendChild(el('label', '', cfg.i18n.providerLabel));
		var providerSelect = el('select', 'widefat');
		providerSelect.id = 'wanyesea-ai-comment-reply-provider';

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

		var modelRow = el('div', 'wanyesea-ai-comment-reply-model-row');
		var modelSelect = el('select', 'widefat');
		modelSelect.id = 'wanyesea-ai-comment-reply-model';
		modelRow.appendChild(modelSelect);

		var loadBtn = el('button', 'button', cfg.i18n.loadModels);
		loadBtn.type = 'button';
		modelRow.appendChild(loadBtn);
		wrap.appendChild(modelRow);

		wrap.appendChild(el('label', '', cfg.i18n.modelCustomLabel));
		var modelCustom = el('input', 'widefat');
		modelCustom.type = 'text';
		modelCustom.id = 'wanyesea-ai-comment-reply-model-custom';
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

		loadModels(providerSelect.value, false, modelSelect, modelCustom, loadBtn);

		return {
			providerSelect: providerSelect,
			modelSelect: modelSelect,
			modelCustom: modelCustom,
		};
	}

	function openModal(commentId) {
		closeModal();

		modalRoot = el('div', 'wanyesea-ai-comment-reply-overlay');
		var dialog = el('div', 'wanyesea-ai-comment-reply-dialog');
		var header = el('div', 'wanyesea-ai-comment-reply-header');
		header.appendChild(el('h2', '', cfg.i18n.title));
		var closeBtn = el('button', 'wanyesea-ai-comment-reply-close', '×');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', cfg.i18n.close);
		header.appendChild(closeBtn);
		dialog.appendChild(header);

		var body = el('div', 'wanyesea-ai-comment-reply-body');
		var messageBox = el('div', 'wanyesea-ai-comment-reply-message');
		messageBox.hidden = true;
		body.appendChild(messageBox);

		if (!cfg.hasModel) {
			showMessage(messageBox, cfg.i18n.noModel, 'error');
			var settingsLink = el('p', '');
			var a = el('a', '', cfg.i18n.openSettings);
			a.href = cfg.settingsUrl;
			a.target = '_blank';
			settingsLink.appendChild(a);
			body.appendChild(settingsLink);
		} else {
			var modelFields = buildProviderModelFields(body);

			body.appendChild(el('label', '', cfg.i18n.noteLabel));
			var noteInput = el('textarea', 'widefat');
			noteInput.rows = 2;
			noteInput.placeholder = cfg.i18n.notePlaceholder;
			body.appendChild(noteInput);

			body.appendChild(el('label', '', cfg.i18n.replyLabel));
			var replyInput = el('textarea', 'widefat');
			replyInput.rows = 5;
			replyInput.placeholder = cfg.i18n.replyPlaceholder;
			body.appendChild(replyInput);

			var actions = el('div', 'wanyesea-ai-comment-reply-actions');
			var generateBtn = el('button', 'button button-primary', cfg.i18n.generate);
			generateBtn.type = 'button';
			var regenerateBtn = el(
				'button',
				'button',
				cfg.i18n.regenerate
			);
			regenerateBtn.type = 'button';
			regenerateBtn.hidden = true;
			var publishBtn = el('button', 'button button-secondary', cfg.i18n.publish);
			publishBtn.type = 'button';
			publishBtn.hidden = true;
			var cancelBtn = el('button', 'button', cfg.i18n.cancel);
			cancelBtn.type = 'button';

			actions.appendChild(generateBtn);
			actions.appendChild(regenerateBtn);
			actions.appendChild(publishBtn);
			actions.appendChild(cancelBtn);
			body.appendChild(actions);

			function requestReply(preview) {
				var payload = {
					comment_id: commentId,
					preview: preview,
					provider_id: modelFields.providerSelect.value,
					model_id: getSelectedModelId(
						modelFields.modelSelect,
						modelFields.modelCustom
					),
					note: noteInput.value,
				};

				if (!preview) {
					payload.reply_text = replyInput.value;
				}

				return apiFetch('/wanyesea-ai/v1/comment-replies', {
					method: 'POST',
					data: payload,
				});
			}

			function setBusy(isBusy, label) {
				generateBtn.disabled = isBusy;
				regenerateBtn.disabled = isBusy;
				publishBtn.disabled = isBusy;
				if (isBusy && label) {
					showMessage(messageBox, label, 'info');
				}
			}

			generateBtn.addEventListener('click', function () {
				setBusy(true, cfg.i18n.generating);
				requestReply(true)
					.then(function (data) {
						replyInput.value = (data && data.reply_text) || '';
						regenerateBtn.hidden = false;
						publishBtn.hidden = false;
						showMessage(messageBox, '', '');
					})
					.catch(function (err) {
						showMessage(
							messageBox,
							(err && err.message) || cfg.i18n.errorGeneric,
							'error'
						);
					})
					.finally(function () {
						setBusy(false, '');
					});
			});

			regenerateBtn.addEventListener('click', function () {
				generateBtn.click();
			});

			publishBtn.addEventListener('click', function () {
				if (!replyInput.value.trim()) {
					showMessage(messageBox, cfg.i18n.replyPlaceholder, 'error');
					return;
				}
				setBusy(true, cfg.i18n.publishing);
				requestReply(false)
					.then(function () {
						showMessage(messageBox, cfg.i18n.published, 'success');
						window.setTimeout(function () {
							window.location.reload();
						}, 900);
					})
					.catch(function (err) {
						showMessage(
							messageBox,
							(err && err.message) || cfg.i18n.errorGeneric,
							'error'
						);
						setBusy(false, '');
					});
			});
		}

		dialog.appendChild(body);
		modalRoot.appendChild(dialog);
		document.body.appendChild(modalRoot);
		document.body.classList.add('wanyesea-ai-comment-reply-modal-open');

		closeBtn.addEventListener('click', closeModal);
		modalRoot.addEventListener('click', function (event) {
			if (event.target === modalRoot) {
				closeModal();
			}
		});

		var cancel = body.querySelector('.wanyesea-ai-comment-reply-actions .button:last-child');
		if (cancel) {
			cancel.addEventListener('click', closeModal);
		}
	}

	document.addEventListener('click', function (event) {
		var link = event.target.closest('.wanyesea-ai-comment-reply-link');
		if (!link) {
			return;
		}
		event.preventDefault();
		var commentId = parseInt(link.getAttribute('data-comment-id'), 10);
		if (!commentId) {
			return;
		}
		openModal(commentId);
	});

	function injectEditScreenButton() {
		var submitBox = document.getElementById('submitdiv');
		var existing = document.querySelector(
			'.wanyesea-ai-comment-reply-link[data-comment-id]'
		);
		if (!submitBox || existing) {
			return;
		}
		var params = new URLSearchParams(window.location.search);
		var commentId = parseInt(params.get('c'), 10);
		if (!commentId) {
			return;
		}
		var p = el('p', '');
		var btn = el(
			'button',
			'button button-secondary wanyesea-ai-comment-reply-link',
			cfg.i18n.title
		);
		btn.type = 'button';
		btn.setAttribute('data-comment-id', String(commentId));
		p.appendChild(btn);
		submitBox.insertBefore(p, submitBox.firstChild);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', injectEditScreenButton);
	} else {
		injectEditScreenButton();
	}
})();
