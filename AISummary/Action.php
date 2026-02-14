<?php

namespace TypechoPlugin\AISummary;

use Typecho\Widget;
use Widget\ActionInterface;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * AI摘要生成 - Action处理类
 *
 * @package AISummary
 */
class Action extends Widget implements ActionInterface
{
    /**
     * 执行函数
     */
    public function execute()
    {
    }

    /**
     * Action入口
     */
    public function action()
    {
        // 验证用户登录状态
        $user = \Widget\User::alloc();
        if (!$user->hasLogin()) {
            $this->sendJson(['success' => false, 'message' => '请先登录']);
            return;
        }

        if ($this->request->is('do=generate')) {
            $this->generate();
        } else {
            $this->sendJson(['success' => false, 'message' => '未知操作']);
        }
    }

    /**
     * 生成摘要
     */
    private function generate()
    {
        $title = $this->request->get('title', '');
        $text  = $this->request->get('text', '');
        $cid   = intval($this->request->get('cid', 0));

        // 如果前端没有传递内容，尝试从数据库获取
        if (empty(trim($text)) && $cid > 0) {
            $db = \Typecho\Db::get();

            // 优先获取草稿版本（更新）
            $draft = $db->fetchRow(
                $db->select()->from('table.contents')
                    ->where('cid = ?', $cid)
                    ->where('type LIKE ?', '%_draft')
                    ->limit(1)
            );

            if ($draft) {
                $title = !empty($title) ? $title : $draft['title'];
                $text  = $draft['text'];
            } else {
                // 获取已发布版本
                $published = $db->fetchRow(
                    $db->select()->from('table.contents')
                        ->where('cid = ?', $cid)
                        ->limit(1)
                );
                if ($published) {
                    $title = !empty($title) ? $title : $published['title'];
                    $text  = $published['text'];
                }
            }
        }

        // 去除Markdown标记前缀
        $text = preg_replace('/^<!--markdown-->/', '', $text);

        if (empty(trim($text))) {
            $this->sendJson(['success' => false, 'message' => '文章内容为空，无法生成摘要']);
            return;
        }

        // 获取插件配置
        try {
            $plugin = Options::alloc()->plugin('AISummary');
        } catch (\Exception $e) {
            $this->sendJson(['success' => false, 'message' => '插件配置未找到，请先在插件设置中完成配置']);
            return;
        }

        $apiUrl         = $plugin->apiUrl;
        $apiKey         = $plugin->apiKey;
        $model          = $plugin->model;
        $promptTemplate = $plugin->prompt;
        $maxLength      = intval($plugin->maxLength) > 0 ? intval($plugin->maxLength) : 20000;

        if (empty($apiKey)) {
            $this->sendJson(['success' => false, 'message' => '请先在插件设置中配置 API Key']);
            return;
        }

        if (empty($apiUrl)) {
            $this->sendJson(['success' => false, 'message' => '请先在插件设置中配置 API 地址']);
            return;
        }

        // 截断过长内容，避免超出API token限制
        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            $text = mb_substr($text, 0, $maxLength, 'UTF-8') . "\n...(内容已截断)";
        }

        // 替换提示词中的占位符
        $prompt = str_replace(
            ['{title}', '{content}'],
            [$title, $text],
            $promptTemplate
        );

        // 调用AI接口
        $result = $this->callApi($apiUrl, $apiKey, $model, $prompt);
        $this->sendJson($result);
    }

    /**
     * 调用兼容OpenAI的API
     *
     * @param string $url     API地址
     * @param string $apiKey  API密钥
     * @param string $model   模型名称
     * @param string $prompt  完整提示词
     * @return array
     */
    private function callApi($url, $apiKey, $model, $prompt)
    {
        $requestBody = json_encode([
            'model'       => $model,
            'messages'    => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7,
            'max_tokens'  => 500
        ], JSON_UNESCAPED_UNICODE);

        $response = null;
        $httpCode = 0;
        $error    = '';

        if (function_exists('curl_init')) {
            // 使用cURL
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $requestBody,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);
        } else {
            // 回退到 file_get_contents
            $context = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n" .
                                 "Authorization: Bearer " . $apiKey . "\r\n",
                    'content' => $requestBody,
                    'timeout' => 120,
                ],
                'ssl' => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                return ['success' => false, 'message' => 'API 请求失败，请检查 API 地址是否正确'];
            }
            $httpCode = 200;
        }

        if (!empty($error)) {
            return ['success' => false, 'message' => 'API 请求失败: ' . $error];
        }

        if ($httpCode !== 200) {
            $errorMsg = 'API 返回错误 (HTTP ' . $httpCode . ')';
            $decoded  = json_decode($response, true);
            if (isset($decoded['error']['message'])) {
                $errorMsg .= ': ' . $decoded['error']['message'];
            } elseif ($response) {
                $errorMsg .= ': ' . mb_substr($response, 0, 200, 'UTF-8');
            }
            return ['success' => false, 'message' => $errorMsg];
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'API 响应解析失败: ' . json_last_error_msg()];
        }

        // 标准 OpenAI 格式
        if (isset($decoded['choices'][0]['message']['content'])) {
            $summary = trim($decoded['choices'][0]['message']['content']);
            return ['success' => true, 'summary' => $summary];
        }

        // 兼容其他格式
        if (isset($decoded['result'])) {
            return ['success' => true, 'summary' => trim($decoded['result'])];
        }

        return [
            'success' => false,
            'message' => 'API 响应格式异常: ' . mb_substr($response, 0, 300, 'UTF-8')
        ];
    }

    /**
     * 输出JSON响应并终止
     *
     * @param array $data
     */
    private function sendJson($data)
    {
        @ob_end_clean();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
