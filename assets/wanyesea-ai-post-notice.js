(function () {
    'use strict';

    function findCopyrightBlock() {
        var postsCopyright = document.querySelector('.posts-copyright');
        if (postsCopyright) {
            var closest = postsCopyright.closest('.em09.muted-3-color');
            if (closest && !closest.classList.contains('wanyesea-ai-post-notice')) {
                return closest;
            }
        }

        var candidates = document.querySelectorAll('.em09.muted-3-color');
        for (var i = 0; i < candidates.length; i++) {
            if (candidates[i].classList.contains('wanyesea-ai-post-notice')) {
                continue;
            }
            if (candidates[i].textContent.indexOf('版权声明') !== -1) {
                return candidates[i];
            }
        }

        return null;
    }

    function initNoticePlacement() {
        var template = document.getElementById('wanyesea-ai-post-notice-template');
        if (!template) {
            return;
        }

        var notice = template.querySelector('.wanyesea-ai-post-notice');
        if (!notice) {
            return;
        }

        template.remove();

        var copyrightEl = findCopyrightBlock();
        if (copyrightEl && copyrightEl.parentNode) {
            var row = document.createElement('div');
            row.className = 'wanyesea-ai-legal-row';
            copyrightEl.parentNode.insertBefore(row, copyrightEl);
            row.appendChild(copyrightEl);
            row.appendChild(notice);
            return;
        }

        var article = document.querySelector('.article-content, article.single-entry, article.post');
        if (article) {
            article.appendChild(notice);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNoticePlacement);
    } else {
        initNoticePlacement();
    }
})();
