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
            if ($this->request->is('do=generate') && !$this->request->is('token')) {
                $this->sendJson(['success' => false, 'message' => '请先登录']);
                return;
            }
            throw new \Typecho\Widget\Exception('请先登录', 403);
        }

        if ($this->request->is('do=generate') && $this->request->get('token')) {
            // 管理面板批量/单篇生成 (GET请求，带token验证)
            $this->generateFromPanel();
        } elseif ($this->request->is('do=generate')) {
            // 编辑器按钮生成 (AJAX POST请求)
            $this->generate();
        } else {
            $this->sendJson(['success' => false, 'message' => '未知操作']);
        }
    }

    /**
     * 编辑器按钮：生成摘要（返回JSON）
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

        $result = $this->callAiAndGetResult($title, $text);
        $this->sendJson($result);
    }

    /**
     * 管理面板：批量/单篇生成摘要（写入数据库并重定向）
     */
    private function generateFromPanel()
    {
        // 验证token
        $requestToken = $this->request->get('token', '');
        try {
            $settingToken = Options::alloc()->plugin('AISummary')->token;
        } catch (\Exception $e) {
            throw new \Typecho\Widget\Exception('插件配置未找到', 500);
        }

        if (empty($requestToken) || $requestToken !== $settingToken) {
            throw new \Typecho\Widget\Exception('令牌验证失败', 403);
        }

        $cids = $this->request->filter('int')->getArray('cid');
        if (empty($cids)) {
            $this->widget('Widget_Notice')->set(_t('未选择任何文章'), null, 'notice');
            $this->response->goBack();
            return;
        }

        $db = \Typecho\Db::get();
        $successCount = 0;
        $failCount = 0;

        foreach ($cids as $cid) {
            $cid = intval($cid);
            if ($cid <= 0) continue;

            // 取出文章内容
            $post = $db->fetchRow(
                $db->select('title', 'text')->from('table.contents')
                    ->where('cid = ?', $cid)
            );

            if (!$post) {
                $failCount++;
                continue;
            }

            $title = $post['title'];
            $text  = preg_replace('/^<!--markdown-->/', '', $post['text']);

            if (empty(trim($text))) {
                $failCount++;
                continue;
            }

            // 调用AI生成摘要
            $result = $this->callAiAndGetResult($title, $text);

            if (!$result['success']) {
                $failCount++;
                continue;
            }

            $summary = $result['summary'];

            // 写入 customSummary 自定义字段
            $exist = $db->fetchRow(
                $db->select('cid')->from('table.fields')
                    ->where('cid = ? AND name = ?', $cid, 'customSummary')
            );

            $rows = [
                'type'        => 'str',
                'str_value'   => $summary,
                'int_value'   => 0,
                'float_value' => 0,
            ];

            if (empty($exist)) {
                $rows['cid']  = $cid;
                $rows['name'] = 'customSummary';
                $db->query($db->insert('table.fields')->rows($rows));
            } else {
                $db->query(
                    $db->update('table.fields')->rows($rows)
                        ->where('cid = ? AND name = ?', $cid, 'customSummary')
                );
            }

            $successCount++;
        }

        // 提示信息
        if ($failCount > 0) {
            $this->widget('Widget_Notice')->set(
                _t('已生成 %d 篇摘要，%d 篇失败', $successCount, $failCount),
                null,
                $successCount > 0 ? 'success' : 'error'
            );
        } else {
            $this->widget('Widget_Notice')->set(
                _t('已成功生成 %d 篇AI摘要', $successCount),
                null,
                'success'
            );
        }

        $this->response->goBack();
    }

    /**
     * 调用AI生成摘要，返回统一格式结果
     *
     * @param string $title 文章标题
     * @param string $text  文章内容
     * @return array ['success' => bool, 'summary' => string] 或 ['success' => false, 'message' => string]
     */
    private function callAiAndGetResult($title, $text)
    {
        // 获取插件配置
        try {
            $plugin = Options::alloc()->plugin('AISummary');
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '插件配置未找到，请先在插件设置中完成配置'];
        }

        $apiUrl         = $plugin->apiUrl;
        $apiKey         = $plugin->apiKey;
        $model          = $plugin->model;
        $promptTemplate = $plugin->prompt;
        $maxLength      = intval($plugin->maxLength) > 0 ? intval($plugin->maxLength) : 20000;

        if (empty($apiKey)) {
            return ['success' => false, 'message' => '请先在插件设置中配置 API Key'];
        }

        if (empty($apiUrl)) {
            return ['success' => false, 'message' => '请先在插件设置中配置 API 地址'];
        }

        // 截断过长内容
        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            $text = mb_substr($text, 0, $maxLength, 'UTF-8') . "\n...(内容已截断)";
        }

        // 替换提示词中的占位符
        $prompt = str_replace(
            ['{title}', '{content}'],
            [$title, $text],
            $promptTemplate
        );

        return $this->callApi($apiUrl, $apiKey, $model, $prompt);
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
