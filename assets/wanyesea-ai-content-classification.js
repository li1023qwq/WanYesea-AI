/**
 * 修正 WordPress AI「内容分类」对中文正文的词数判断。
 *
 * 官方逻辑：wp.wordcount.count( getEditedPostContent(), 'words' ) >= 150
 * 中文无空格时整段常只计为极少「词」，导致误显示「约 150 个词」提示且按钮禁用。
 */
(function () {
	'use strict';

	var cfg = window.wanyeseaAiContentClassification || {};
	var minWords = typeof cfg.minWords === 'number' ? cfg.minWords : 150;
	var cjkCharsPerWord =
		typeof cfg.cjkCharsPerWord === 'number' && cfg.cjkCharsPerWord > 0
			? cfg.cjkCharsPerWord
			: 2;
	var useCjk =
		cfg.useCjkAdjustment !== false &&
		(cfg.isZhLocale === true || isZhLocale());

	function isZhLocale() {
		var lang = (document.documentElement.lang || '').toLowerCase();
		return lang === 'zh' || lang.indexOf('zh-') === 0;
	}

	function stripTags(text) {
		var el = document.createElement('div');
		el.innerHTML = text || '';
		return el.textContent || '';
	}

	function countCjkLetters(text) {
		var plain = stripTags(text);
		var m = plain.match(
			/[\u4e00-\u9fff\u3400-\u4dbf\uf900-\ufaff]/g
		);
		return m ? m.length : 0;
	}

	function patchWordcount() {
		var wc = window.wp && window.wp.wordcount;
		if (!wc || typeof wc.count !== 'function' || wc.count.__wanyeseaPatched) {
			return !!(
				window.wp &&
				window.wp.wordcount &&
				window.wp.wordcount.count &&
				window.wp.wordcount.count.__wanyeseaPatched
			);
		}

		var original = wc.count.bind(wc);

		wc.count = function (text, type, userSettings) {
			if (type !== 'words' || !useCjk) {
				return original(text, type, userSettings);
			}

			var words = original(text, type, userSettings);
			if (words >= minWords) {
				return words;
			}

			var equivalent = Math.floor(
				countCjkLetters(text) / cjkCharsPerWord
			);
			return Math.max(words, equivalent);
		};

		wc.count.__wanyeseaPatched = true;
		return true;
	}

	if (!patchWordcount()) {
		var attempts = 0;
		var timer = window.setInterval(function () {
			if (patchWordcount() || ++attempts >= 50) {
				window.clearInterval(timer);
			}
		}, 50);
	}
})();
