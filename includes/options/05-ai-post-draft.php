<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

CSF::createSection(
    $prefix,
    array(
        'title'  => 'AI 草稿',
        'icon'   => 'fa fa-file-text-o',
        'class'  => 'wya-section-ai-post-draft',
        'fields' => array(
            array(
                'type'    => 'submessage',
                'style'   => 'info',
                'content' => '<div class="wya-post-draft-hero">
                    <h3 class="wya-post-draft-hero__title"><i class="fa fa-file-text-o fa-fw"></i>AI 文章草稿</h3>
                    <p>在后台「文章 → 所有文章」点击 <strong>AI 草稿</strong>，可批量生成符合子比主题格式的草稿。此处配置前台展示行为。</p>
                    <p class="muted-3-color em09">前台提示样式对齐子比文章页「© 版权声明」区块（<code>em09 muted-3-color</code> + <code>posts-copyright</code>）。</p>
                </div>',
            ),
            array(
                'id'       => 'post_draft_ai_notice_s',
                'type'     => 'switcher',
                'title'    => '文章底部 AI 生成提示',
                'subtitle' => '在 AI 生成的文章正文下方显示声明（样式与子比版权声明一致）',
                'default'  => true,
            ),
            array(
                'dependency' => array('post_draft_ai_notice_s', '!=', ''),
                'id'         => 'post_draft_ai_notice_title',
                'type'       => 'text',
                'title'      => '提示标题',
                'subtitle'   => '显示在 © 图标旁的小标题',
                'default'    => 'AI 内容声明',
            ),
            array(
                'dependency' => array('post_draft_ai_notice_s', '!=', ''),
                'id'         => 'post_draft_ai_notice_text',
                'type'       => 'textarea',
                'title'      => '提示正文',
                'subtitle'   => '对应子比「版权声明」正文区域',
                'default'    => '本文由 AI 辅助生成，内容仅供参考，请核实后使用。',
                'attributes' => array(
                    'rows' => 3,
                ),
            ),
        ),
    )
);
