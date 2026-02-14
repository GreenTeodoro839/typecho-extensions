<?php
namespace TypechoPlugin\SendMail;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Layout;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 评论回复邮件提醒插件
 * 当用户的评论被回复时，通过 SMTP 向用户邮箱发送提醒邮件
 *
 * @package SendMail
 * @author  小猪
 * @version 1.1.0
 * @link    https://www.zcec.top
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        // 前台/后台新评论
        \Typecho\Plugin::factory('Widget\\Feedback')->finishComment = [__CLASS__, 'onFinishComment'];
        \Typecho\Plugin::factory('Widget\\Comments\\Edit')->finishComment = [__CLASS__, 'onFinishComment'];
        // 后台手动审核（通过/垃圾/待审核）
        \Typecho\Plugin::factory('Widget\\Comments\\Edit')->mark = [__CLASS__, 'onCommentMark'];
        return _t('SendMail 插件已激活，请进入设置配置 SMTP 信息');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        return _t('SendMail 插件已禁用');
    }

    /**
     * 插件配置面板
     */
    public static function config(Form $form)
    {
        // ===== SMTP 服务器设置 =====
        $smtpHeader = new Layout('div');
        $smtpHeader->html('<h3>' . _t('SMTP 服务器设置') . '</h3>');
        $form->addItem($smtpHeader);

        $smtpHost = new Text(
            'smtpHost', NULL, 'smtp.qq.com',
            _t('SMTP 服务器地址'),
            _t('例如：smtp.qq.com、smtp.163.com、smtp.gmail.com')
        );
        $form->addInput($smtpHost);

        $smtpPort = new Text(
            'smtpPort', NULL, '465',
            _t('SMTP 端口'),
            _t('常用端口：25（无加密）、465（SSL）、587（TLS/STARTTLS）')
        );
        $form->addInput($smtpPort);

        $smtpSecure = new Radio(
            'smtpSecure',
            array(
                'ssl' => 'SSL',
                'tls' => 'TLS',
                'none' => _t('无加密'),
            ),
            'ssl',
            _t('加密方式')
        );
        $form->addInput($smtpSecure);

        $smtpUser = new Text(
            'smtpUser', NULL, '',
            _t('SMTP 用户名'),
            _t('通常是你的邮箱地址')
        );
        $form->addInput($smtpUser);

        $smtpPass = new Password(
            'smtpPass', NULL, '',
            _t('SMTP 密码/授权码'),
            _t('QQ邮箱、163邮箱等需要使用授权码而非登录密码')
        );
        $form->addInput($smtpPass);

        // ===== 发件人设置 =====
        $senderHeader = new Layout('div');
        $senderHeader->html('<h3>' . _t('发件人设置') . '</h3>');
        $form->addItem($senderHeader);

        $fromName = new Text(
            'fromName', NULL, '',
            _t('发件人名称'),
            _t('留空则使用博客名称')
        );
        $form->addInput($fromName);

        $fromEmail = new Text(
            'fromEmail', NULL, '',
            _t('发件人邮箱'),
            _t('留空则使用 SMTP 用户名')
        );
        $form->addInput($fromEmail);

        // ===== 邮件模板设置 =====
        $templateHeader = new Layout('div');
        $templateHeader->html('<h3>' . _t('邮件模板设置') . '</h3>');
        $form->addItem($templateHeader);

        $mailSubject = new Text(
            'mailSubject', NULL,
            '您在「{blogName}」的评论收到了回复',
            _t('邮件标题模板'),
            _t('可用变量：{blogName} {postTitle} {author} {originalAuthor}')
        );
        $form->addInput($mailSubject);

        $defaultTemplate = self::getDefaultTemplate();

        $mailBody = new Textarea(
            'mailBody', NULL,
            $defaultTemplate,
            _t('邮件内容模板（HTML）'),
            _t('可用变量：{blogName} {blogUrl} {postTitle} {postUrl} {author} {authorTag} {replyContent} {originalAuthor} {originalContent} {year}<br>'
                . '{authorTag} 为博主回复时显示的特殊标签，非博主回复时为空')
        );
        $mailBody->input->setAttribute('style', 'width:100%;height:400px;font-family:monospace;font-size:13px;');
        $form->addInput($mailBody);

        $ownerTag = new Text(
            'ownerTag', NULL,
            '<span style="background:#e74c3c;color:#fff;padding:1px 6px;border-radius:3px;font-size:12px;margin-left:5px;">博主</span>',
            _t('博主标签 HTML'),
            _t('当回复者是博主时，{authorTag} 将替换为此内容')
        );
        $form->addInput($ownerTag);

        // ===== 高级设置 =====
        $advancedHeader = new Layout('div');
        $advancedHeader->html('<h3>' . _t('高级设置') . '</h3>');
        $form->addItem($advancedHeader);

        $debug = new Radio(
            'debug',
            array(
                '0' => _t('关闭'),
                '1' => _t('开启'),
            ),
            '0',
            _t('调试模式'),
            _t('开启后会在 Typecho 日志中记录邮件发送详情')
        );
        $form->addInput($debug);
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 获取默认邮件模板
     */
    public static function getDefaultTemplate()
    {
        $templateFile = __DIR__ . '/template.html';
        if (file_exists($templateFile)) {
            return file_get_contents($templateFile);
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:'Helvetica Neue',Helvetica,Arial,'PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
    <tr>
        <td style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:30px 40px;">
            <h1 style="margin:0;color:#fff;font-size:22px;font-weight:500;">💬 您的评论收到了新回复</h1>
        </td>
    </tr>
    <tr>
        <td style="padding:30px 40px;">
            <p style="color:#555;font-size:15px;line-height:1.6;margin:0 0 20px;">
                <strong>{originalAuthor}</strong>，您好！您在文章
                <a href="{postUrl}" style="color:#667eea;text-decoration:none;font-weight:500;">「{postTitle}」</a>
                中的评论收到了来自 <strong>{author}</strong>{authorTag} 的回复：
            </p>
            <div style="background:#f0f3ff;border-left:4px solid #667eea;padding:15px 20px;border-radius:0 6px 6px 0;margin:0 0 20px;">
                <p style="margin:0 0 5px;font-size:13px;color:#999;">回复内容：</p>
                <p style="margin:0;color:#333;font-size:15px;line-height:1.8;">{replyContent}</p>
            </div>
            <div style="background:#f9f9f9;border-left:4px solid #ddd;padding:15px 20px;border-radius:0 6px 6px 0;margin:0 0 25px;">
                <p style="margin:0 0 5px;font-size:13px;color:#999;">您的原始评论：</p>
                <p style="margin:0;color:#666;font-size:14px;line-height:1.8;">{originalContent}</p>
            </div>
            <a href="{postUrl}" style="display:inline-block;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:10px 28px;border-radius:5px;text-decoration:none;font-size:14px;">查看完整内容</a>
        </td>
    </tr>
    <tr>
        <td style="background:#fafafa;padding:20px 40px;border-top:1px solid #eee;">
            <p style="margin:0;color:#999;font-size:12px;text-align:center;">
                此邮件由 <a href="{blogUrl}" style="color:#667eea;text-decoration:none;">{blogName}</a> 自动发送 · {year}
            </p>
        </td>
    </tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    /**
     * finishComment 钩子：新评论提交时触发
     * 收集评论 coid，交给共用方法异步处理
     *
     * @param mixed $comment 评论 Widget 对象
     */
    public static function onFinishComment($comment)
    {
        // 没有父评论 => 直接留言，不发送
        if (empty($comment->parent) || $comment->parent == 0) {
            return;
        }

        self::prepareAndQueueMail($comment->coid);
    }

    /**
     * mark 钩子：后台手动审核评论状态时触发
     * Typecho 在 DB 更新前调用此钩子，但 shutdown 时 DB 已更新
     *
     * @param array  $comment 评论数据（DB 行，改之前的状态）
     * @param mixed  $edit    Widget\Comments\Edit 实例
     * @param string $status  目标状态（approved / waiting / spam）
     */
    public static function onCommentMark($comment, $edit, $status)
    {
        // 只处理「变为 approved」的情况（之前不是 approved）
        if ($status !== 'approved' || $comment['status'] === 'approved') {
            return;
        }

        // 必须是回复评论
        if (empty($comment['parent'])) {
            return;
        }

        self::prepareAndQueueMail($comment['coid']);
    }

    /**
     * 核心方法：根据 coid 准备邮件数据并注册异步发送
     *
     * @param int $coid 当前评论（回复）的 coid
     */
    private static function prepareAndQueueMail($coid)
    {
        try {
            $db = \Typecho\Db::get();
        } catch (\Throwable $e) {
            self::log("数据库连接失败: " . $e->getMessage());
            return;
        }

        // 查询当前评论
        $comment = $db->fetchRow(
            $db->select()->from('table.comments')
                ->where('coid = ?', $coid)
                ->limit(1)
        );

        if (empty($comment) || empty($comment['parent'])) {
            return;
        }

        // 查询父评论（被回复的评论）
        $parentComment = $db->fetchRow(
            $db->select()->from('table.comments')
                ->where('coid = ?', $comment['parent'])
                ->limit(1)
        );

        if (empty($parentComment)) {
            return;
        }

        // 父评论没有邮箱，无法发送
        if (empty($parentComment['mail'])) {
            return;
        }

        // 获取文章作者 uid（即博主）
        $options = Options::alloc();
        $siteOwnerId = 1;
        $post = $db->fetchRow(
            $db->select('authorId')->from('table.contents')
                ->where('cid = ?', $comment['cid'])
                ->limit(1)
        );
        if (!empty($post)) {
            $siteOwnerId = $post['authorId'];
        }

        // 父评论作者是博主 => 不发送
        if (!empty($parentComment['authorId']) && $parentComment['authorId'] == $siteOwnerId) {
            return;
        }

        // 不要给自己回复自己发邮件
        if ($parentComment['mail'] === $comment['mail']) {
            return;
        }

        // 获取插件配置
        $pluginConfig = $options->plugin('SendMail');
        $debug = intval($pluginConfig->debug);

        // 判断回复者是否是博主
        $isOwner = false;
        if (!empty($comment['authorId']) && $comment['authorId'] == $siteOwnerId) {
            $isOwner = true;
        } else {
            try {
                $currentUser = \Widget\User::alloc();
                if ($currentUser->hasLogin() && $currentUser->uid == $siteOwnerId) {
                    $isOwner = true;
                }
            } catch (\Throwable $e) {
                // 忽略
            }
        }

        // 获取文章信息
        $postData = $db->fetchRow(
            $db->select('title', 'slug', 'type', 'cid')->from('table.contents')
                ->where('cid = ?', $comment['cid'])
                ->limit(1)
        );
        $postTitle = !empty($postData['title']) ? $postData['title'] : '未知文章';

        // 构建文章链接
        try {
            $postWidget = \Widget\Archive::alloc('cid=' . $comment['cid']);
            $postUrl = $postWidget->permalink;
        } catch (\Throwable $e) {
            $postUrl = $options->siteUrl;
        }

        // 模板变量
        $authorTag = $isOwner ? $pluginConfig->ownerTag : '';
        $fromName  = !empty($pluginConfig->fromName) ? $pluginConfig->fromName : $options->title;
        $fromEmail = !empty($pluginConfig->fromEmail) ? $pluginConfig->fromEmail : $pluginConfig->smtpUser;

        $variables = array(
            '{blogName}'        => $options->title,
            '{blogUrl}'         => $options->siteUrl,
            '{postTitle}'       => $postTitle,
            '{postUrl}'         => $postUrl,
            '{author}'          => $comment['author'],
            '{authorTag}'       => $authorTag,
            '{replyContent}'    => nl2br(htmlspecialchars($comment['text'])),
            '{originalAuthor}'  => $parentComment['author'],
            '{originalContent}' => nl2br(htmlspecialchars($parentComment['text'])),
            '{year}'            => date('Y'),
        );

        $subject = str_replace(array_keys($variables), array_values($variables), $pluginConfig->mailSubject);
        $body    = str_replace(array_keys($variables), array_values($variables), $pluginConfig->mailBody);

        // 打包发送参数，注册到 shutdown 函数中异步发送
        $mailData = array(
            'coid'       => $coid,
            'smtpHost'   => $pluginConfig->smtpHost,
            'smtpPort'   => intval($pluginConfig->smtpPort),
            'smtpSecure' => $pluginConfig->smtpSecure,
            'smtpUser'   => $pluginConfig->smtpUser,
            'smtpPass'   => $pluginConfig->smtpPass,
            'fromEmail'  => $fromEmail,
            'fromName'   => $fromName,
            'toEmail'    => $parentComment['mail'],
            'toName'     => $parentComment['author'],
            'subject'    => $subject,
            'body'       => $body,
            'debug'      => $debug,
        );

        register_shutdown_function([__CLASS__, 'asyncSend'], $mailData);
    }

    /**
     * 异步发送邮件（在 shutdown 阶段执行）
     * 先尝试 fastcgi_finish_request() 立即结束响应，再执行耗时的 SMTP 操作
     *
     * @param array $mailData 邮件参数
     */
    public static function asyncSend($mailData)
    {
        // 尝试尽早结束 HTTP 响应，让用户不必等待
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // 防止脚本超时
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        ignore_user_abort(true);

        // 刷新输出缓冲区（Apache / mod_php 场景）
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();

        // 重新查询评论状态，只有审核通过（approved）才发送
        // 此时 CommentHelper 的 AI 审核已经执行完毕
        try {
            $db = \Typecho\Db::get();
            $row = $db->fetchRow(
                $db->select('status')->from('table.comments')
                    ->where('coid = ?', $mailData['coid'])
                    ->limit(1)
            );

            if (empty($row) || $row['status'] !== 'approved') {
                if ($mailData['debug']) {
                    $status = isset($row['status']) ? $row['status'] : 'not_found';
                    self::log("邮件未发送: coid={$mailData['coid']}, 评论状态={$status}，需要 approved 才发送");
                }
                return;
            }
        } catch (\Throwable $e) {
            self::log("检查评论状态失败: " . $e->getMessage() . "，为安全起见取消发送");
            return;
        }

        try {
            require_once __DIR__ . '/Smtp.php';

            $smtp = new Smtp(
                $mailData['smtpHost'],
                $mailData['smtpPort'],
                $mailData['smtpSecure'],
                $mailData['smtpUser'],
                $mailData['smtpPass'],
                $mailData['debug']
            );

            $smtp->send(
                $mailData['fromEmail'],
                $mailData['fromName'],
                $mailData['toEmail'],
                $mailData['toName'],
                $mailData['subject'],
                $mailData['body']
            );

            if ($mailData['debug']) {
                self::log("邮件发送成功: to={$mailData['toEmail']}, subject={$mailData['subject']}");
            }
        } catch (\Throwable $e) {
            self::log("邮件发送失败: " . $e->getMessage());
        }
    }

    /**
     * 记录日志
     */
    private static function log($message)
    {
        $logFile = __TYPECHO_ROOT_DIR__ . '/usr/plugins/SendMail/logs/sendmail.log';
        $logDir  = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $time = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[{$time}] {$message}\n", FILE_APPEND | LOCK_EX);
    }
}
