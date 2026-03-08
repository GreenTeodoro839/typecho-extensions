<?php

namespace TypechoPlugin\AISummary;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Radio;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * AI摘要生成 - 在文章编辑页面使用AI自动生成文章摘要，支持兼容OpenAI的大模型API
 *
 * @package AISummary
 * @author 小猪
 * @version 1.1.0
 * @link https://www.zcec.top
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        // 编辑器按钮
        \Typecho\Plugin::factory('admin/write-post.php')->bottom = __CLASS__ . '::renderButton';
        \Typecho\Plugin::factory('admin/write-page.php')->bottom = __CLASS__ . '::renderButton';

        // 正文显示摘要
        \Typecho\Plugin::factory('Widget_Abstract_Contents')->contentEx = __CLASS__ . '::customContent';

        // 自定义CSS加载到头部
        \Typecho\Plugin::factory('Widget_Archive')->header = __CLASS__ . '::header';

        // 管理面板
        \Utils\Helper::addPanel(3, 'AISummary/manage-summaries.php', '摘要', '管理AI摘要', 'administrator');

        // Action路由
        \Utils\Helper::addAction('ai-summary', Action::class);
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        \Utils\Helper::removePanel(3, 'AISummary/manage-summaries.php');
        \Utils\Helper::removeAction('ai-summary');
    }

    /**
     * 插件配置面板
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        // ========== API 设置 ==========
        $apiUrl = new Text(
            'apiUrl',
            null,
            'https://api.openai.com/v1/chat/completions',
            _t('API 地址'),
            _t('兼容 OpenAI 的大模型 API 地址，例如 https://api.openai.com/v1/chat/completions')
        );
        $form->addInput($apiUrl);

        $apiKey = new Text(
            'apiKey',
            null,
            '',
            _t('API Key'),
            _t('大模型 API 密钥')
        );
        $form->addInput($apiKey);

        $model = new Text(
            'model',
            null,
            'gpt-4o-mini',
            _t('模型名称'),
            _t('使用的 AI 模型名称，例如 gpt-4o-mini、deepseek-chat 等')
        );
        $form->addInput($model);

        $maxLength = new Text(
            'maxLength',
            null,
            '20000',
            _t('内容截断长度'),
            _t('发送给 AI 的文章内容最大字符数，超出部分将被截断。不同模型上下文窗口不同，请根据实际情况调整，默认 20000 字符')
        );
        $form->addInput($maxLength);

        $prompt = new Textarea(
            'prompt',
            null,
            "请为以下文章生成一段简洁的摘要，不超过100字，直接输出文字摘要内容即可，不要包含任何前缀或解释，不要使用Markdown。\n\n标题：{title}\n\n内容：{content}",
            _t('提示词模板'),
            _t('使用 {title} 代表文章标题，{content} 代表文章内容')
        );
        $form->addInput($prompt);

        // ========== 正文摘要显示设置 ==========
        $summaryStyle = new Radio(
            'summaryStyle',
            ['0' => '不显示', '1' => '使用默认引用样式', '2' => '使用自定义样式'],
            '0',
            _t('正文摘要显示样式'),
            _t('选择在正文开头以何种样式显示摘要')
        );
        $form->addInput($summaryStyle);

        $prefix = new Text(
            'prefix',
            null,
            '<strong>AI摘要：</strong>{{text}}',
            _t('正文摘要模板'),
            _t('正文中摘要的显示模板，用 {{text}} 代表摘要内容，仅在正文摘要显示时生效')
        );
        $form->addInput($prefix);

        $css = new Textarea(
            'css',
            null,
            "<style>\n.aisummary{\n}\n</style>",
            _t('自定义样式'),
            _t('加载到 head 标签中的自定义 CSS，摘要元素 class="aisummary"，需包含 &lt;style&gt; 标签。如无需求可留空')
        );
        $form->addInput($css);

        // ========== 管理面板设置 ==========
        $token = new Text(
            'token',
            null,
            self::createUuid(),
            _t('请求令牌'),
            _t('用于管理面板批量生成摘要的请求验证令牌')
        );
        $form->addInput($token);
    }

    /**
     * 个人用户配置面板
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 在正文开头插入摘要显示
     *
     * @param string $content 文章正文
     * @param mixed  $widget  文章对象
     * @return string
     */
    public static function customContent($content, $widget)
    {
        $options = Options::alloc()->plugin('AISummary');
        if ($options->summaryStyle === '0') {
            return $content;
        }

        $summary = $widget->fields->customSummary;
        if (empty($summary)) {
            return $content;
        }

        $summaryHtml = str_replace('{{text}}', htmlspecialchars($summary), $options->prefix);

        if ($options->summaryStyle === '1') {
            $block = '<blockquote class="aisummary">' . $summaryHtml . '</blockquote>';
        } else {
            $block = '<div class="aisummary">' . $summaryHtml . '</div>';
        }

        return $block . $content;
    }

    /**
     * 在 head 中加载自定义 CSS
     */
    public static function header()
    {
        $css = Options::alloc()->plugin('AISummary')->css;
        if (!empty($css)) {
            echo $css;
        }
    }

    /**
     * 在编辑器底部注入AI生成摘要按钮的JavaScript
     *
     * @param mixed $post 文章/页面对象
     */
    public static function renderButton($post)
    {
        $security = \Widget\Security::alloc();
        $actionUrl = $security->getIndex('/action/ai-summary');
        ?>
        <script type="text/javascript">
        (function($) {
            $(document).ready(function() {
                // 查找摘要输入框（Handsome主题的自定义字段）
                var summaryInput = $('[name="fields[customSummary]"]');
                if (summaryInput.length === 0) return;

                var actionUrl = <?php echo json_encode($actionUrl); ?>;

                // 创建按钮
                var btn = $('<button type="button" id="ai-summary-btn"></button>');
                btn.text('🤖 AI生成摘要');
                btn.css({
                    'margin-top': '6px',
                    'margin-bottom': '2px',
                    'padding': '4px 14px',
                    'background': '#467B96',
                    'color': '#fff',
                    'border': 'none',
                    'border-radius': '3px',
                    'cursor': 'pointer',
                    'font-size': '12px',
                    'display': 'inline-block',
                    'line-height': '1.6',
                    'transition': 'opacity 0.2s'
                });
                btn.hover(
                    function() { $(this).css('opacity', '0.85'); },
                    function() { $(this).css('opacity', '1'); }
                );

                // 创建消息提示
                var msgEl = $('<span id="ai-summary-msg"></span>').css({
                    'margin-left': '10px',
                    'font-size': '12px',
                    'vertical-align': 'middle'
                });

                // 在输入框后面插入按钮和消息
                summaryInput.after(msgEl).after(btn);

                // 点击事件
                btn.click(function() {
                    var title = $('#title').val();
                    var text = $('#text').val();
                    var cid = $('input[name="cid"]').val() || '0';

                    if (!text || !text.trim()) {
                        msgEl.css('color', '#c00').text('✗ 文章内容为空');
                        setTimeout(function() { msgEl.text(''); }, 3000);
                        return;
                    }

                    btn.prop('disabled', true).css('opacity', '0.6').text('⏳ 正在生成...');
                    msgEl.css('color', '#999').text('');

                    $.ajax({
                        url: actionUrl,
                        method: 'POST',
                        data: {
                            do: 'generate',
                            title: title,
                            text: text,
                            cid: cid
                        },
                        dataType: 'json',
                        timeout: 120000,
                        success: function(res) {
                            if (res.success) {
                                summaryInput.val(res.summary).trigger('change');
                                msgEl.css('color', '#5cb85c').text('✓ 摘要已生成');
                            } else {
                                msgEl.css('color', '#c00').text('✗ ' + res.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            var msg = '请求失败';
                            if (status === 'timeout') {
                                msg = 'AI响应超时，请重试';
                            } else {
                                try {
                                    var res = JSON.parse(xhr.responseText);
                                    msg = res.message || msg;
                                } catch(e) {
                                    msg = msg + ' (' + (error || status) + ')';
                                }
                            }
                            msgEl.css('color', '#c00').text('✗ ' + msg);
                        },
                        complete: function() {
                            btn.prop('disabled', false).css('opacity', '1').text('🤖 AI生成摘要');
                            setTimeout(function() { msgEl.text(''); }, 5000);
                        }
                    });
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * 生成UUID
     *
     * @return string
     */
    public static function createUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
