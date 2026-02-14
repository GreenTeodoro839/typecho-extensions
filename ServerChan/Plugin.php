<?php
namespace TypechoPlugin\ServerChan;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Server酱评论通知插件 - 当有新评论时通过Server酱推送通知
 *
 * @package ServerChan
 * @author 小猪
 * @version 1.0.0
 * @link https://www.zcec.top
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        \Typecho\Plugin::factory('Widget\Feedback')->finishComment = [__CLASS__, 'sendNotification'];
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        // 停用时无需处理
    }

    /**
     * 插件配置面板
     */
    public static function config(Form $form)
    {
        $sendKey = new Text(
            'sendKey',
            null,
            '',
            _t('SendKey'),
            _t('Server酱的 SendKey，可从 <a href="https://sc3.ft07.com/sendkey" target="_blank">SendKey页面</a> 获取。支持 SC3（sctp开头）和 SCT 两种格式。')
        );
        $form->addInput($sendKey);

        $notifyTitle = new Text(
            'notifyTitle',
            null,
            '博客「{siteTitle}」有新评论',
            _t('通知标题'),
            _t('推送通知的标题。可用替代符：<br>'
                . '<code>{author}</code> - 评论者昵称<br>'
                . '<code>{email}</code> - 评论者邮箱<br>'
                . '<code>{url}</code> - 评论者网站<br>'
                . '<code>{text}</code> - 评论内容<br>'
                . '<code>{postTitle}</code> - 文章标题<br>'
                . '<code>{permalink}</code> - 文章链接<br>'
                . '<code>{ip}</code> - 评论者IP<br>'
                . '<code>{date}</code> - 评论时间<br>'
                . '<code>{siteTitle}</code> - 博客名称<br>'
                . '<code>{status}</code> - 评论状态')
        );
        $form->addInput($notifyTitle);

        $notifyContent = new Textarea(
            'notifyContent',
            null,
            "### 新评论通知\n\n"
            . "**文章：** [{postTitle}]({permalink})\n\n"
            . "---\n\n"
            . "| 项目 | 内容 |\n"
            . "| --- | --- |\n"
            . "| **昵称** | {author} |\n"
            . "| **邮箱** | {email} |\n"
            . "| **网站** | {url} |\n"
            . "| **IP** | {ip} |\n"
            . "| **时间** | {date} |\n"
            . "| **状态** | {status} |\n\n"
            . "---\n\n"
            . "**评论内容：**\n\n"
            . "> {text}\n",
            _t('通知内容'),
            _t('推送通知的正文内容，支持 Markdown 格式。可用替代符同上。')
        );
        $form->addInput($notifyContent);

        $notifyTags = new Text(
            'notifyTags',
            null,
            '博客评论',
            _t('标签（Tags）'),
            _t('Server酱消息标签，多个标签使用竖线 <code>|</code> 分隔。可用替代符同上。例如：<code>博客评论|{postTitle}</code>')
        );
        $form->addInput($notifyTags);

        $notifyShort = new Text(
            'notifyShort',
            null,
            '{author} 评论了「{postTitle}」：{text}',
            _t('消息卡片简述'),
            _t('消息卡片的简短描述（short），适用于 desp 为 Markdown 时的卡片预览。可用替代符同上。')
        );
        $form->addInput($notifyShort);

        $skipOwner = new \Typecho\Widget\Helper\Form\Element\Radio(
            'skipOwner',
            ['1' => _t('是'), '0' => _t('否')],
            '1',
            _t('忽略自己的评论'),
            _t('当博主自己发表评论/回复时不发送通知。')
        );
        $form->addInput($skipOwner);
    }

    /**
     * 个人用户配置面板
     */
    public static function personalConfig(Form $form)
    {
        // 个人配置为空
    }

    /**
     * 评论完成后发送通知
     *
     * @param mixed $comment 评论对象
     */
    public static function sendNotification($comment)
    {
        $options = Options::alloc();
        $pluginOptions = $options->plugin('ServerChan');

        $sendKey = $pluginOptions->sendKey;
        if (empty($sendKey)) {
            return;
        }

        // 如果设置了忽略博主评论
        if ($pluginOptions->skipOwner == '1') {
            $user = \Widget\User::alloc();
            if ($user->hasLogin() && $user->uid == $comment->ownerId) {
                return;
            }
        }

        // 获取文章信息
        $postTitle = self::getPostTitle($comment);
        $permalink = self::getPostPermalink($comment);

        // 评论状态映射
        $statusMap = [
            'approved' => '已通过',
            'waiting'  => '待审核',
            'spam'     => '垃圾评论',
        ];
        $status = isset($statusMap[$comment->status]) ? $statusMap[$comment->status] : $comment->status;

        // 构建替代符数组
        $replacements = [
            '{author}'    => $comment->author ?? '',
            '{email}'     => $comment->mail ?? '',
            '{url}'       => $comment->url ?? '',
            '{text}'      => $comment->text ?? '',
            '{postTitle}' => $postTitle,
            '{permalink}' => $permalink,
            '{ip}'        => $comment->ip ?? '',
            '{date}'      => date('Y-m-d H:i:s', $comment->created),
            '{siteTitle}' => $options->title,
            '{status}'    => $status,
        ];

        // 替换模板中的占位符
        $title   = self::replacePlaceholders($pluginOptions->notifyTitle, $replacements);
        $content = self::replacePlaceholders($pluginOptions->notifyContent, $replacements);
        $tags    = self::replacePlaceholders($pluginOptions->notifyTags, $replacements);
        $short   = self::replacePlaceholders($pluginOptions->notifyShort, $replacements);

        // 发送通知
        self::scSend($sendKey, $title, $content, [
            'tags'  => $tags,
            'short' => $short,
        ]);
    }

    /**
     * 获取文章标题
     */
    private static function getPostTitle($comment)
    {
        try {
            $db = \Typecho\Db::get();
            $post = $db->fetchRow($db->select('title')->from('table.contents')->where('cid = ?', $comment->cid));
            return $post ? $post['title'] : '未知文章';
        } catch (\Exception $e) {
            return '未知文章';
        }
    }

    /**
     * 获取文章链接
     */
    private static function getPostPermalink($comment)
    {
        try {
            $widget = \Widget\Archive::alloc('cid=' . $comment->cid);
            return $widget->permalink;
        } catch (\Exception $e) {
            return Options::alloc()->siteUrl;
        }
    }

    /**
     * 替换模板中的占位符
     *
     * @param string $template 模板字符串
     * @param array  $replacements 替代符数组
     * @return string
     */
    private static function replacePlaceholders($template, $replacements)
    {
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * 调用 Server酱 API 发送推送
     * 兼容 SC3（sctp开头）和 SCT 两种 SendKey
     *
     * @param string $sendKey  SendKey
     * @param string $title    推送标题
     * @param string $desp     推送正文（支持Markdown）
     * @param array  $options  可选参数 (tags, short, etc.)
     * @return array|false 返回结果或失败时返回false
     */
    private static function scSend($sendKey, $title, $desp = '', $options = [])
    {
        // 根据 sendkey 类型构造 URL
        if (strpos($sendKey, 'sctp') === 0) {
            // SC3 格式
            $url = 'https://' . $sendKey . '.push.ft07.com/send';
        } else {
            // SCT 格式
            $url = 'https://sctapi.ftqq.com/' . $sendKey . '.send';
        }

        $params = array_merge([
            'title' => $title,
            'desp'  => $desp,
        ], $options);

        // 移除空值参数
        $params = array_filter($params, function ($v) {
            return $v !== '' && $v !== null;
        });

        $jsonData = json_encode($params, JSON_UNESCAPED_UNICODE);

        // 优先使用 cURL
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $jsonData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json;charset=utf-8',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
        } else {
            // 降级使用 file_get_contents
            $opts = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => 'Content-Type: application/json;charset=utf-8',
                    'content' => $jsonData,
                    'timeout' => 10,
                ],
                'ssl' => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ],
            ];
            $context  = stream_context_create($opts);
            $response = @file_get_contents($url, false, $context);
        }

        if ($response !== false) {
            return json_decode($response, true);
        }

        return false;
    }
}
