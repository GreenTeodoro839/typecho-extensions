<?php

namespace TypechoPlugin\TypechoMcp;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 为 Typecho 提供原生 MCP（Model Context Protocol）服务。
 *
 * @package TypechoMcp
 * @author GreenTeodoro839
 * @version 1.2.0
 * @since 1.3.0
 * @link https://github.com/GreenTeodoro839/typecho-extensions
 */
class Plugin implements PluginInterface
{
    public static function activate()
    {
        \Utils\Helper::addAction('typecho-mcp', Action::class);
        return _t('Typecho MCP 已启用，请妥善保管访问令牌');
    }

    public static function deactivate()
    {
        \Utils\Helper::removeAction('typecho-mcp');
    }

    public static function config(Form $form)
    {
        $token = new Text(
            'token',
            null,
            bin2hex(random_bytes(32)),
            _t('访问令牌'),
            _t('MCP 客户端需要通过 Authorization: Bearer &lt;令牌&gt; 访问。请勿公开此令牌。')
        );
        $form->addInput($token);

        $maxUploadMb = new Text(
            'maxUploadMb',
            null,
            '10',
            _t('单文件上限（MB）'),
            _t('仅影响 MCP 的 Base64 文件上传；扩展名仍受 Typecho 全局附件白名单限制。')
        );
        $form->addInput($maxUploadMb);
    }

    public static function personalConfig(Form $form)
    {
    }
}
