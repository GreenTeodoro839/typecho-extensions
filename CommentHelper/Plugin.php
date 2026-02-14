<?php
namespace TypechoPlugin\CommentHelper;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Server酱评论通知 + AI自动审核插件
 * 新评论时通过AI大模型自动审核，再通过Server酱推送通知
 *
 * @package CommentHelper
 * @author 小猪
 * @version 2.0.0
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
                . '<code>{status}</code> - 评论状态<br>'
                . '<code>{aiResult}</code> - AI审核结果（启用AI审核时可用）')
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
            . "> {text}\n\n"
            . "**AI审核：** {aiResult}\n",
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

        // ===== AI 自动审核设置 =====
        $form->addInput(new Text(
            'aiApiUrl',
            null,
            'https://api.openai.com/v1/chat/completions',
            _t('【AI审核】API 地址'),
            _t('兼容 OpenAI 的大模型 API 地址，留空则不启用 AI 审核。例如 https://api.openai.com/v1/chat/completions')
        ));

        $form->addInput(new Text(
            'aiApiKey',
            null,
            '',
            _t('【AI审核】API Key'),
            _t('大模型 API 密钥，留空则不启用 AI 审核。')
        ));

        $form->addInput(new Text(
            'aiModel',
            null,
            'gpt-4o-mini',
            _t('【AI审核】模型名称'),
            _t('使用的 AI 模型，例如 gpt-4o-mini、deepseek-chat 等。')
        ));

        $form->addInput(new Textarea(
            'aiPrompt',
            null,
            "你是一个博客评论审核助手。请判断以下评论是否应该通过审核。\n"
            . "通过条件：内容正常、无违规、无恶意推广、无垃圾信息、无无意义内容（如纯表情、乱码、测试等）。\n"
            . "拒绝条件：包含违规内容、恶意推广/广告链接、垃圾信息、无意义灌水、攻击性言论。\n\n"
            . "评论信息：\n"
            . "- 昵称：{author}\n"
            . "- 邮箱：{email}\n"
            . "- 网站：{url}\n"
            . "- IP：{ip}\n"
            . "- 评论内容：{text}\n"
            . "- 所属文章：{postTitle}\n\n"
            . "请严格以JSON格式返回，不要输出其他内容：\n"
            . "{\"approved\": true或false, \"reason\": \"简短审核理由\"}",
            _t('【AI审核】审核提示词'),
            _t('发送给 AI 的审核提示词模板。可用替代符与通知模板相同。AI 必须返回 JSON 格式：<code>{"approved": true/false, "reason": "理由"}</code>')
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Radio(
            'aiReviewEnabled',
            ['1' => _t('启用'), '0' => _t('关闭')],
            '0',
            _t('【AI审核】启用自动审核'),
            _t('开启后，待审核评论将自动交给 AI 判断是否通过。审核失败（如 API 异常）则保持待审核状态不处理。')
        ));
    }

    /**
     * 个人用户配置面板
     */
    public static function personalConfig(Form $form)
    {
        // 个人配置为空
    }

    /**
     * 评论完成后：先 AI 审核，再发送通知
     *
     * @param mixed $comment 评论对象
     */
    public static function sendNotification($comment)
    {
        $options = Options::alloc();
        $pluginOptions = $options->plugin('CommentHelper');

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

        // 构建替代符数组（审核和通知共用）
        $statusMap = [
            'approved' => '已通过',
            'waiting'  => '待审核',
            'spam'     => '垃圾评论',
        ];
        $currentStatus = $comment->status;

        // ===== AI 自动审核 =====
        if ($pluginOptions->aiReviewEnabled == '1' && $currentStatus == 'waiting') {
            $reviewResult = self::aiReviewComment($comment, $pluginOptions, $postTitle, $permalink);
            if ($reviewResult !== null) {
                // 审核成功，更新评论状态
                $currentStatus = $reviewResult['approved'] ? 'approved' : 'spam';
                self::updateCommentStatus($comment->coid, $currentStatus);
            }
            // reviewResult === null 表示 AI 调用失败，保持 waiting 不处理
        }

        // ===== ServerChan 通知 =====
        $sendKey = $pluginOptions->sendKey;
        if (empty($sendKey)) {
            return;
        }

        $status = isset($statusMap[$currentStatus]) ? $statusMap[$currentStatus] : $currentStatus;

        // 如果经过AI审核，在状态后附加审核理由
        $aiInfo = '';
        if (isset($reviewResult) && $reviewResult !== null) {
            $aiInfo = $reviewResult['approved'] ? '✅ AI审核通过' : '❌ AI审核拒绝';
            if (!empty($reviewResult['reason'])) {
                $aiInfo .= '（' . $reviewResult['reason'] . '）';
            }
        }

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
            '{aiResult}'  => $aiInfo,
        ];

        $title   = self::replacePlaceholders($pluginOptions->notifyTitle, $replacements);
        $content = self::replacePlaceholders($pluginOptions->notifyContent, $replacements);
        $tags    = self::replacePlaceholders($pluginOptions->notifyTags, $replacements);
        $short   = self::replacePlaceholders($pluginOptions->notifyShort, $replacements);

        self::scSend($sendKey, $title, $content, [
            'tags'  => $tags,
            'short' => $short,
        ]);
    }

    /**
     * AI 审核评论
     *
     * @param mixed  $comment       评论对象
     * @param mixed  $pluginOptions 插件配置
     * @param string $postTitle     文章标题
     * @param string $permalink     文章链接
     * @return array|null 返回 ['approved' => bool, 'reason' => string] 或 null（失败）
     */
    private static function aiReviewComment($comment, $pluginOptions, $postTitle, $permalink)
    {
        $apiUrl = $pluginOptions->aiApiUrl;
        $apiKey = $pluginOptions->aiApiKey;
        $model  = $pluginOptions->aiModel;
        $prompt = $pluginOptions->aiPrompt;

        if (empty($apiUrl) || empty($apiKey)) {
            return null;
        }

        // 替换提示词中的占位符
        $replacements = [
            '{author}'    => $comment->author ?? '',
            '{email}'     => $comment->mail ?? '',
            '{url}'       => $comment->url ?? '',
            '{text}'      => $comment->text ?? '',
            '{postTitle}' => $postTitle,
            '{permalink}' => $permalink ?? '',
            '{ip}'        => $comment->ip ?? '',
            '{date}'      => date('Y-m-d H:i:s', $comment->created),
        ];
        $prompt = self::replacePlaceholders($prompt, $replacements);

        // 构造请求体，使用 response_format 强制 JSON 输出
        $requestBody = json_encode([
            'model'           => $model ?: 'gpt-4o-mini',
            'messages'        => [
                ['role' => 'system', 'content' => '你是一个博客评论审核助手，请严格按照JSON格式返回审核结果。'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature'     => 0.3,
            'max_tokens'      => 200,
        ], JSON_UNESCAPED_UNICODE);

        // 调用 API
        $response = self::callOpenAiApi($apiUrl, $apiKey, $requestBody);
        if ($response === false) {
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // 提取 AI 回复内容
        $content = '';
        if (isset($decoded['choices'][0]['message']['content'])) {
            $content = trim($decoded['choices'][0]['message']['content']);
        } elseif (isset($decoded['result'])) {
            $content = trim($decoded['result']);
        }

        if (empty($content)) {
            return null;
        }

        // 解析 JSON 结果
        $result = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($result['approved'])) {
            return null;
        }

        return [
            'approved' => (bool) $result['approved'],
            'reason'   => isset($result['reason']) ? (string) $result['reason'] : '',
        ];
    }

    /**
     * 更新评论状态
     *
     * @param int    $coid   评论 ID
     * @param string $status 新状态 (approved / spam / waiting)
     */
    private static function updateCommentStatus($coid, $status)
    {
        try {
            $db = \Typecho\Db::get();
            $db->query($db->update('table.comments')
                ->rows(['status' => $status])
                ->where('coid = ?', $coid));
        } catch (\Exception $e) {
            // 更新失败静默处理
        }
    }

    /**
     * 调用兼容 OpenAI 的 API
     *
     * @param string $url         API 地址
     * @param string $apiKey      API Key
     * @param string $requestBody JSON 请求体
     * @return string|false 响应内容或 false
     */
    private static function callOpenAiApi($url, $apiKey, $requestBody)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $requestBody,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || $response === false) {
                return false;
            }
            return $response;
        }

        // 降级 file_get_contents
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nAuthorization: Bearer " . $apiKey . "\r\n",
                'content' => $requestBody,
                'timeout' => 30,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        return $response !== false ? $response : false;
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
