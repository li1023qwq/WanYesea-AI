<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

CSF::createSection(
    $prefix,
    array(
        'title'  => 'AI 评论回复',
        'icon'   => 'fa fa-comments-o',
        'class'  => 'wya-section-ai-comment-reply',
        'fields' => array(
            array(
                'type'    => 'submessage',
                'style'   => 'info',
                'content' => '<div class="wya-comment-reply-hero">
                    <h3 class="wya-comment-reply-hero__title"><i class="fa fa-comments-o fa-fw"></i>AI 评论回复</h3>
                    <p><strong>开启后自动回复：</strong>读者在子比文章评论区发表评论并过审后，系统会异步根据文章内容生成个性化回复并发布（无需管理员手动操作）。</p>
                    <p class="muted-3-color em09">后台「评论」列表的 <strong>AI 回复</strong> 按钮仅用于手动补发；子比「快捷回复」及下方自定义屏蔽语句将自动跳过。</p>
                </div>',
            ),
            array(
                'id'       => 'comment_reply_enabled',
                'type'     => 'switcher',
                'title'    => '启用 AI 评论回复',
                'subtitle' => '开启后，读者评论过审将自动触发 AI 回复',
                'default'  => false,
            ),
            array(
                'dependency' => array('comment_reply_enabled', '!=', ''),
                'id'         => 'comment_reply_auto_on_approve',
                'type'       => 'switcher',
                'title'      => '新评论自动回复',
                'subtitle'   => '评论审核通过（含前台直接过审）后，后台异步生成并发布',
                'default'    => true,
            ),
            array(
                'dependency' => array('comment_reply_enabled', '!=', ''),
                'id'         => 'comment_reply_auto_approve',
                'type'       => 'switcher',
                'title'      => 'AI 回复自动过审',
                'subtitle'   => '关闭时 AI 回复以「待审核」状态写入，需人工确认',
                'default'    => true,
            ),
            array(
                'dependency' => array('comment_reply_enabled', '!=', ''),
                'id'         => 'comment_reply_show_badge',
                'type'       => 'switcher',
                'title'      => '前台显示 AI 标识',
                'subtitle'   => '在子比评论区用户名旁显示 AI 小标签',
                'default'    => true,
            ),
            array(
                'dependency' => array('comment_reply_enabled', '!=', ''),
                'id'         => 'comment_reply_author_mode',
                'type'       => 'select',
                'title'      => '回复身份',
                'subtitle'   => '决定 AI 回复以哪位用户名义发表',
                'options'    => array(
                    'post_author' => '文章作者',
                    'site_admin'  => '站点管理员（首个管理员账号）',
                    'custom'      => '指定用户',
                ),
                'default'    => 'post_author',
            ),
            array(
                'dependency' => array('comment_reply_author_mode', '==', 'custom'),
                'id'         => 'comment_reply_custom_user_id',
                'type'       => 'number',
                'title'      => '指定用户 ID',
                'subtitle'   => '可在「用户」列表查看 ID',
                'default'    => 1,
            ),
            array(
                'dependency' => array('comment_reply_enabled', '!=', ''),
                'id'         => 'comment_reply_max_chars',
                'type'       => 'number',
                'title'      => '回复最大字数',
                'subtitle'   => '限制 AI 输出长度，建议 200–800',
                'default'    => 500,
            ),
            array(
                'dependency' => array('comment_reply_enabled', '!=', ''),
                'id'         => 'comment_reply_block_zibll_quick',
                'type'       => 'switcher',
                'title'      => '屏蔽子比快捷回复',
                'subtitle'   => '与主题「系统快捷回复」或评论者「我的快捷回复」完全一致的评论不触发 AI 回复',
                'default'    => true,
            ),
            array(
                'dependency' => array('comment_reply_enabled', '!=', ''),
                'id'         => 'comment_reply_block_phrases',
                'type'       => 'textarea',
                'title'      => '自定义屏蔽语句',
                'subtitle'   => '每行一条；评论内容与任一句完全一致（去首尾空白后）则跳过 AI 回复',
                'default'    => '',
                'attributes' => array(
                    'rows'        => 6,
                    'placeholder' => "例如：\n感谢分享\n学习了\n占楼",
                ),
            ),
            array(
                'dependency' => array('comment_reply_enabled', '!=', ''),
                'id'         => 'comment_reply_extra_instructions',
                'type'       => 'textarea',
                'title'      => '默认补充说明',
                'subtitle'   => '注入每次生成提示词，例如站点风格、禁用话题等',
                'default'    => '保持真诚、简洁，避免营销腔；不确定的内容请引导读者以文章为准。',
                'attributes' => array(
                    'rows' => 3,
                ),
            ),
        ),
    )
);
