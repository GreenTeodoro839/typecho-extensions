<?php

namespace TypechoPlugin\TypechoMcp;

use Typecho\Common;
use Typecho\Config;
use Typecho\Db;
use Typecho\Router;
use Typecho\Widget;
use Widget\ActionInterface;
use Widget\Options;
use Widget\Upload;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action extends Widget implements ActionInterface
{
    private const SERVER_NAME = 'typecho-mcp';
    private const SERVER_VERSION = '1.1.0';
    private const LATEST_PROTOCOL = '2025-11-25';
    private const SUPPORTED_PROTOCOLS = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
        '2024-10-07',
    ];

    private Db $db;
    private Options $options;
    private Config $settings;

    public function execute()
    {
    }

    public function action()
    {
        $this->setCommonHeaders();

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            $this->response->setStatus(204);
            http_response_code(204);
            exit;
        }

        try {
            $this->db = Db::get();
            $this->options = Options::alloc();
            $this->settings = $this->options->plugin('TypechoMcp');
        } catch (\Throwable $e) {
            $this->sendHttpError(500, 'Typecho MCP 插件尚未正确配置');
        }

        if (!$this->authenticate()) {
            header('WWW-Authenticate: Bearer realm="Typecho MCP"');
            $this->sendHttpError(401, '无效或缺失的访问令牌');
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'POST') {
            header('Allow: POST, OPTIONS');
            $this->sendHttpError(405, 'MCP 端点仅接受 POST 请求');
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            $this->sendJsonRpcError(null, -32600, '请求正文不能为空', 400);
        }

        $request = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendJsonRpcError(null, -32700, 'JSON 解析失败', 400);
        }

        if ($this->isList($request)) {
            $responses = [];
            foreach ($request as $item) {
                $response = $this->handleRequest($item);
                if ($response !== null) {
                    $responses[] = $response;
                }
            }

            if (empty($responses)) {
                $this->response->setStatus(202);
                http_response_code(202);
                exit;
            }

            $this->sendPayload($responses);
        }

        $response = $this->handleRequest($request);
        if ($response === null) {
            $this->response->setStatus(202);
            http_response_code(202);
            exit;
        }

        $this->sendPayload($response);
    }

    private function handleRequest($request): ?array
    {
        if (!is_array($request) || ($request['jsonrpc'] ?? null) !== '2.0' || empty($request['method'])) {
            return $this->errorResponse($request['id'] ?? null, -32600, '无效的 JSON-RPC 请求');
        }

        $hasId = array_key_exists('id', $request);
        $id = $request['id'] ?? null;
        $method = (string)$request['method'];
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        if (!$hasId) {
            return null;
        }

        try {
            switch ($method) {
                case 'initialize':
                    $requested = (string)($params['protocolVersion'] ?? '');
                    $protocol = in_array($requested, self::SUPPORTED_PROTOCOLS, true)
                        ? $requested
                        : self::LATEST_PROTOCOL;

                    return $this->resultResponse($id, [
                        'protocolVersion' => $protocol,
                        'capabilities' => [
                            'tools' => ['listChanged' => false],
                        ],
                        'serverInfo' => [
                            'name' => self::SERVER_NAME,
                            'title' => 'Typecho MCP',
                            'version' => self::SERVER_VERSION,
                        ],
                        'instructions' => '管理当前 Typecho 站点的文章与附件。创建文章默认保存为草稿；发布前请明确征得用户同意。',
                    ]);

                case 'ping':
                    return $this->resultResponse($id, new \stdClass());

                case 'tools/list':
                    return $this->resultResponse($id, ['tools' => $this->toolDefinitions()]);

                case 'tools/call':
                    $name = (string)($params['name'] ?? '');
                    $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                    return $this->resultResponse($id, $this->callTool($name, $arguments));

                default:
                    return $this->errorResponse($id, -32601, '不支持的方法: ' . $method);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($id, -32602, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[TypechoMcp] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->errorResponse($id, -32603, '服务器内部错误');
        }
    }

    private function toolDefinitions(): array
    {
        return [
            [
                'name' => 'get_site_info',
                'title' => '查看博客信息',
                'description' => '查看 Typecho 版本、站点地址、文章数量、附件类型和 MCP 上传限制。',
                'inputSchema' => $this->objectSchema(),
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true],
            ],
            [
                'name' => 'list_posts',
                'title' => '列出或搜索文章',
                'description' => '按状态和关键词列出文章。默认不返回完整正文。',
                'inputSchema' => $this->objectSchema([
                    'status' => [
                        'type' => 'string',
                        'enum' => ['any', 'draft', 'publish', 'private', 'hidden', 'waiting'],
                        'default' => 'any',
                    ],
                    'search' => ['type' => 'string', 'description' => '在标题和正文中搜索'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                    'include_content' => ['type' => 'boolean', 'default' => false],
                ]),
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true],
            ],
            [
                'name' => 'get_post',
                'title' => '读取文章',
                'description' => '按文章 cid 读取完整正文、分类、标签、关联附件及 Handsome 主题头图/摘要配置。',
                'inputSchema' => $this->objectSchema([
                    'cid' => ['type' => 'integer', 'minimum' => 1],
                ], ['cid']),
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true],
            ],
            [
                'name' => 'list_categories',
                'title' => '查看分类',
                'description' => '列出可用于创建或修改文章的 Typecho 分类。',
                'inputSchema' => $this->objectSchema(),
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true],
            ],
            [
                'name' => 'create_post',
                'title' => '创建文章',
                'description' => '创建 Markdown 或 HTML 文章，并可设置摘要、头图等 Handsome 主题字段。默认 status=draft，不会直接公开发布。',
                'inputSchema' => $this->postWriteSchema(true),
                'annotations' => ['destructiveHint' => false, 'idempotentHint' => false],
            ],
            [
                'name' => 'update_post',
                'title' => '修改文章',
                'description' => '修改已有文章的标题、正文、状态、分类、标签、评论设置及 Handsome 主题字段。',
                'inputSchema' => $this->postWriteSchema(false),
                'annotations' => ['destructiveHint' => true, 'idempotentHint' => true],
            ],
            [
                'name' => 'list_attachments',
                'title' => '查看附件',
                'description' => '列出已上传的图片或文件，可按关联文章筛选。',
                'inputSchema' => $this->objectSchema([
                    'parent_cid' => ['type' => 'integer', 'minimum' => 0],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ]),
                'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true],
            ],
            [
                'name' => 'upload_file',
                'title' => '上传图片或文件',
                'description' => '通过 Base64 上传附件，可选关联到文章。文件扩展名受 Typecho 全局附件白名单限制。',
                'inputSchema' => $this->objectSchema([
                    'filename' => ['type' => 'string', 'minLength' => 1, 'description' => '包含扩展名的原始文件名'],
                    'data_base64' => ['type' => 'string', 'minLength' => 1, 'description' => '文件内容的 Base64；也接受 data:...;base64,...'],
                    'parent_cid' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ], ['filename', 'data_base64']),
                'annotations' => ['destructiveHint' => false, 'idempotentHint' => false],
            ],
        ];
    }

    private function postWriteSchema(bool $create): array
    {
        $properties = [
            'title' => ['type' => 'string', 'minLength' => 1],
            'content' => ['type' => 'string'],
            'format' => ['type' => 'string', 'enum' => ['markdown', 'html']],
            'slug' => ['type' => 'string'],
            'status' => [
                'type' => 'string',
                'enum' => ['draft', 'publish', 'private', 'hidden', 'waiting'],
            ],
            'category_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer', 'minimum' => 1],
                'uniqueItems' => true,
            ],
            'tags' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'minLength' => 1],
                'uniqueItems' => true,
            ],
            'allow_comment' => ['type' => 'boolean'],
            'allow_ping' => ['type' => 'boolean'],
            'allow_feed' => ['type' => 'boolean'],
            'custom_summary' => [
                'type' => 'string',
                'maxLength' => 20000,
                'description' => '手动指定的文章摘要；传空字符串可清除',
            ],
            'hero_display' => [
                'type' => 'string',
                'enum' => ['default', 'both', 'index_only', 'post_only', 'none'],
                'description' => '头图显示位置',
            ],
            'hero_image' => [
                'type' => 'string',
                'maxLength' => 2048,
                'description' => '大头图 URL，推荐 8:3；传空字符串可清除',
            ],
            'hero_image_small' => [
                'type' => 'string',
                'maxLength' => 2048,
                'description' => '首页小头图 URL，推荐正方形；传空字符串可清除',
            ],
            'hero_image_credit' => [
                'type' => 'string',
                'maxLength' => 1000,
                'description' => '头图版权或来源说明',
            ],
            'hero_style' => [
                'type' => 'string',
                'enum' => ['default', 'large', 'small', 'picture'],
                'description' => '单篇文章头图样式',
            ],
            'badge_style' => [
                'type' => 'string',
                'enum' => ['default', 'book', 'game', 'note', 'chat', 'code', 'image', 'web', 'link', 'design', 'lock'],
                'description' => '无头图时显示的个性化标徽',
            ],
            'badge_emoji' => [
                'type' => 'string',
                'maxLength' => 64,
                'description' => '无头图且未选标徽时显示的 Emoji',
            ],
            'outdated_notice' => [
                'type' => 'boolean',
                'description' => '是否开启文章过时提醒',
            ],
            'reprint_rule' => [
                'type' => 'string',
                'enum' => ['standard', 'paid', 'forbidden', 'source_site', 'internet'],
                'description' => '文章转载规则',
            ],
            'mathjax' => [
                'type' => 'string',
                'enum' => ['auto', 'enabled', 'disabled'],
                'description' => '单篇文章 MathJax 设置',
            ],
            'markdown_parser' => [
                'type' => 'string',
                'enum' => ['auto', 'origin', 'vditor'],
                'description' => '前台 Markdown 解析器',
            ],
        ];

        if (!$create) {
            $properties = ['cid' => ['type' => 'integer', 'minimum' => 1]] + $properties;
        }

        $schema = $this->objectSchema($properties, $create ? ['title', 'content'] : ['cid']);
        if ($create) {
            $schema['properties']['format']['default'] = 'markdown';
            $schema['properties']['status']['default'] = 'draft';
            $schema['properties']['allow_comment']['default'] = true;
            $schema['properties']['allow_ping']['default'] = true;
            $schema['properties']['allow_feed']['default'] = true;
            $schema['properties']['hero_display']['default'] = 'default';
            $schema['properties']['hero_style']['default'] = 'default';
            $schema['properties']['badge_style']['default'] = 'default';
            $schema['properties']['outdated_notice']['default'] = false;
            $schema['properties']['reprint_rule']['default'] = 'standard';
            $schema['properties']['mathjax']['default'] = 'auto';
            $schema['properties']['markdown_parser']['default'] = 'auto';
        }
        return $schema;
    }

    private function objectSchema(array $properties = [], array $required = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => empty($properties) ? new \stdClass() : $properties,
            'additionalProperties' => false,
        ];
        if (!empty($required)) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    private function callTool(string $name, array $arguments): array
    {
        try {
            switch ($name) {
                case 'get_site_info':
                    $data = $this->getSiteInfo();
                    break;
                case 'list_posts':
                    $data = $this->listPosts($arguments);
                    break;
                case 'get_post':
                    $data = $this->getPost($this->positiveInt($arguments, 'cid'));
                    break;
                case 'list_categories':
                    $data = ['categories' => $this->categories()];
                    break;
                case 'create_post':
                    $data = $this->createPost($arguments);
                    break;
                case 'update_post':
                    $data = $this->updatePost($arguments);
                    break;
                case 'list_attachments':
                    $data = $this->listAttachments($arguments);
                    break;
                case 'upload_file':
                    $data = $this->uploadFile($arguments);
                    break;
                default:
                    throw new \InvalidArgumentException('未知工具: ' . $name);
            }

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => $this->encode($data, true),
                ]],
                'structuredContent' => $data,
            ];
        } catch (\InvalidArgumentException $e) {
            return $this->toolError($e->getMessage());
        } catch (\Throwable $e) {
            error_log('[TypechoMcp tool:' . $name . '] ' . $e->getMessage());
            return $this->toolError('工具执行失败');
        }
    }

    private function getSiteInfo(): array
    {
        $postCount = $this->db->fetchObject(
            $this->db->select(['COUNT(cid)' => 'num'])->from('table.contents')
                ->where('type IN ?', ['post', 'post_draft'])
        )->num;
        $attachmentCount = $this->db->fetchObject(
            $this->db->select(['COUNT(cid)' => 'num'])->from('table.contents')
                ->where('type = ?', 'attachment')
        )->num;

        return [
            'title' => $this->options->title,
            'site_url' => rtrim($this->options->siteUrl, '/'),
            'typecho_version' => Common::VERSION,
            'mcp_server_version' => self::SERVER_VERSION,
            'posts' => (int)$postCount,
            'attachments' => (int)$attachmentCount,
            'allowed_attachment_types' => array_values($this->options->allowedAttachmentTypes),
            'max_upload_mb' => $this->maxUploadMb(),
        ];
    }

    private function listPosts(array $args): array
    {
        $status = (string)($args['status'] ?? 'any');
        $allowedStatuses = ['any', 'draft', 'publish', 'private', 'hidden', 'waiting'];
        if (!in_array($status, $allowedStatuses, true)) {
            throw new \InvalidArgumentException('status 参数无效');
        }

        $limit = $this->boundedInt($args['limit'] ?? 20, 1, 100, 'limit');
        $offset = $this->boundedInt($args['offset'] ?? 0, 0, 1000000, 'offset');
        $includeContent = (bool)($args['include_content'] ?? false);
        $search = trim((string)($args['search'] ?? ''));

        $select = $this->db->select()->from('table.contents')
            ->where('type IN ?', ['post', 'post_draft']);

        if ($status === 'draft') {
            $select->where('type = ?', 'post_draft');
        } elseif ($status !== 'any') {
            $select->where('type = ?', 'post')->where('status = ?', $status);
        }

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $select->where('(title LIKE ? OR text LIKE ?)', $like, $like);
        }

        $rows = $this->db->fetchAll(
            $select->order('modified', Db::SORT_DESC)->limit($limit)->offset($offset)
        );

        $posts = [];
        foreach ($rows as $row) {
            $posts[] = $this->formatPost($row, $includeContent, false);
        }

        return [
            'posts' => $posts,
            'limit' => $limit,
            'offset' => $offset,
            'returned' => count($posts),
        ];
    }

    private function getPost(int $cid): array
    {
        $row = $this->db->fetchRow(
            $this->db->select()->from('table.contents')
                ->where('cid = ?', $cid)
                ->where('type IN ?', ['post', 'post_draft'])
                ->limit(1)
        );
        if (!$row) {
            throw new \InvalidArgumentException('文章不存在');
        }

        $post = $this->formatPost($row, true, true);
        $post['attachments'] = $this->attachmentRows(
            $this->db->select()->from('table.contents')
                ->where('type = ?', 'attachment')
                ->where('parent = ?', $cid)
                ->order('created', Db::SORT_DESC)
        );
        return $post;
    }

    private function categories(): array
    {
        $rows = $this->db->fetchAll(
            $this->db->select('mid', 'name', 'slug', 'description', 'count', 'parent', 'order')
                ->from('table.metas')
                ->where('type = ?', 'category')
                ->order('order', Db::SORT_ASC)
        );
        foreach ($rows as &$row) {
            $row['mid'] = (int)$row['mid'];
            $row['count'] = (int)$row['count'];
            $row['parent'] = (int)$row['parent'];
            $row['order'] = (int)$row['order'];
        }
        unset($row);
        return $rows;
    }

    private function createPost(array $args): array
    {
        $title = trim((string)($args['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('title 不能为空');
        }
        if (!array_key_exists('content', $args)) {
            throw new \InvalidArgumentException('content 不能为空');
        }

        $status = $this->normalizeStatus($args['status'] ?? 'draft');
        $format = $this->normalizeFormat($args['format'] ?? 'markdown');
        $themeFields = $this->normalizeThemeFields($args, true);
        $now = $this->options->time;
        $authorId = $this->defaultAuthorId();
        [$type, $dbStatus] = $this->storageStatus($status);

        $rows = [
            'title' => htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'slug' => '',
            'created' => $now,
            'modified' => $now,
            'text' => $this->prepareContent((string)$args['content'], $format),
            'order' => 0,
            'authorId' => $authorId,
            'template' => null,
            'type' => $type,
            'status' => $dbStatus,
            'password' => null,
            'commentsNum' => 0,
            'allowComment' => $this->boolInt($args['allow_comment'] ?? true),
            'allowPing' => $this->boolInt($args['allow_ping'] ?? true),
            'allowFeed' => $this->boolInt($args['allow_feed'] ?? true),
            'parent' => 0,
        ];

        $cid = (int)$this->db->query($this->db->insert('table.contents')->rows($rows));
        if ($cid <= 0) {
            throw new \RuntimeException('创建文章失败');
        }

        $slug = $this->applySlug((string)($args['slug'] ?? ''), $cid, $title);
        $categoryIds = array_key_exists('category_ids', $args)
            ? $this->intArray($args['category_ids'], 'category_ids')
            : [(int)$this->options->defaultCategory];
        $tags = array_key_exists('tags', $args) ? $this->stringArray($args['tags'], 'tags') : [];
        $this->replaceMetas($cid, $categoryIds, $tags, true, true);
        $this->saveThemeFields($cid, $themeFields);

        $row = $this->db->fetchRow(
            $this->db->select()->from('table.contents')->where('cid = ?', $cid)->limit(1)
        );
        $result = $this->formatPost($row, true, true);
        $result['slug'] = $slug;
        $result['created_now'] = true;
        return $result;
    }

    private function updatePost(array $args): array
    {
        $cid = $this->positiveInt($args, 'cid');
        $row = $this->db->fetchRow(
            $this->db->select()->from('table.contents')
                ->where('cid = ?', $cid)
                ->where('type IN ?', ['post', 'post_draft'])
                ->limit(1)
        );
        if (!$row) {
            throw new \InvalidArgumentException('文章不存在');
        }

        $themeFields = $this->normalizeThemeFields($args, false);
        $updates = ['modified' => $this->options->time];
        if (array_key_exists('title', $args)) {
            $title = trim((string)$args['title']);
            if ($title === '') {
                throw new \InvalidArgumentException('title 不能为空');
            }
            $updates['title'] = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (array_key_exists('content', $args)) {
            $format = array_key_exists('format', $args)
                ? $this->normalizeFormat($args['format'])
                : (str_starts_with((string)$row['text'], '<!--markdown-->') ? 'markdown' : 'html');
            $updates['text'] = $this->prepareContent((string)$args['content'], $format);
        }
        if (array_key_exists('status', $args)) {
            [$updates['type'], $updates['status']] = $this->storageStatus(
                $this->normalizeStatus($args['status'])
            );
        }
        foreach ([
            'allow_comment' => 'allowComment',
            'allow_ping' => 'allowPing',
            'allow_feed' => 'allowFeed',
        ] as $input => $column) {
            if (array_key_exists($input, $args)) {
                $updates[$column] = $this->boolInt($args[$input]);
            }
        }

        $this->db->query(
            $this->db->update('table.contents')->rows($updates)->where('cid = ?', $cid)
        );

        if (array_key_exists('slug', $args)) {
            $this->applySlug((string)$args['slug'], $cid, html_entity_decode($updates['title'] ?? $row['title']));
        }

        $hasCategories = array_key_exists('category_ids', $args);
        $hasTags = array_key_exists('tags', $args);
        if ($hasCategories || $hasTags) {
            $categoryIds = $hasCategories
                ? $this->intArray($args['category_ids'], 'category_ids')
                : [];
            $tags = $hasTags ? $this->stringArray($args['tags'], 'tags') : [];
            $this->replaceMetas($cid, $categoryIds, $tags, $hasCategories, $hasTags);
        }
        $this->refreshRelatedMetaCounts($cid);
        $this->saveThemeFields($cid, $themeFields);

        $updated = $this->db->fetchRow(
            $this->db->select()->from('table.contents')->where('cid = ?', $cid)->limit(1)
        );
        $result = $this->formatPost($updated, true, true);
        $result['updated_now'] = true;
        return $result;
    }

    private function listAttachments(array $args): array
    {
        $limit = $this->boundedInt($args['limit'] ?? 20, 1, 100, 'limit');
        $offset = $this->boundedInt($args['offset'] ?? 0, 0, 1000000, 'offset');
        $select = $this->db->select()->from('table.contents')
            ->where('type = ?', 'attachment');
        if (array_key_exists('parent_cid', $args)) {
            $parent = $this->boundedInt($args['parent_cid'], 0, PHP_INT_MAX, 'parent_cid');
            $select->where('parent = ?', $parent);
        }
        $select->order('created', Db::SORT_DESC)->limit($limit)->offset($offset);

        $attachments = $this->attachmentRows($select);
        return [
            'attachments' => $attachments,
            'limit' => $limit,
            'offset' => $offset,
            'returned' => count($attachments),
        ];
    }

    private function uploadFile(array $args): array
    {
        $filename = trim((string)($args['filename'] ?? ''));
        if ($filename === '' || !str_contains($filename, '.')) {
            throw new \InvalidArgumentException('filename 必须包含有效扩展名');
        }

        $base64 = (string)($args['data_base64'] ?? '');
        if (preg_match('/^data:[^;]+;base64,(.*)$/s', $base64, $matches)) {
            $base64 = $matches[1];
        }
        $base64 = preg_replace('/\s+/', '', $base64);
        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new \InvalidArgumentException('data_base64 不是有效的 Base64');
        }

        $size = strlen($bytes);
        $maxBytes = $this->maxUploadMb() * 1024 * 1024;
        if ($size === 0) {
            throw new \InvalidArgumentException('不能上传空文件');
        }
        if ($size > $maxBytes) {
            throw new \InvalidArgumentException('文件超过 MCP 上传上限 ' . $this->maxUploadMb() . ' MB');
        }

        $parent = $this->boundedInt($args['parent_cid'] ?? 0, 0, PHP_INT_MAX, 'parent_cid');
        if ($parent > 0) {
            $parentRow = $this->db->fetchRow(
                $this->db->select('cid')->from('table.contents')
                    ->where('cid = ?', $parent)
                    ->where('type IN ?', ['post', 'post_draft'])
                    ->limit(1)
            );
            if (!$parentRow) {
                throw new \InvalidArgumentException('parent_cid 对应的文章不存在');
            }
        }

        $upload = Upload::uploadHandle([
            'name' => $filename,
            'bytes' => $bytes,
            'size' => $size,
        ]);
        if ($upload === false) {
            throw new \InvalidArgumentException('上传失败：请检查扩展名是否在 Typecho 附件白名单中');
        }

        $now = $this->options->time;
        $cid = (int)$this->db->query(
            $this->db->insert('table.contents')->rows([
                'title' => htmlspecialchars($upload['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'slug' => '',
                'created' => $now,
                'modified' => $now,
                'text' => json_encode($upload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'order' => 0,
                'authorId' => $this->defaultAuthorId(),
                'template' => null,
                'type' => 'attachment',
                'status' => $parent > 0 ? 'hidden' : 'publish',
                'password' => null,
                'commentsNum' => 0,
                'allowComment' => 0,
                'allowPing' => 0,
                'allowFeed' => 1,
                'parent' => $parent,
            ])
        );
        if ($cid <= 0) {
            Upload::deleteHandle(['attachment' => new Config($upload)]);
            throw new \RuntimeException('附件记录创建失败');
        }
        $this->applySlug($upload['name'], $cid, $upload['name']);

        return $this->formatAttachment([
            'cid' => $cid,
            'title' => $upload['name'],
            'created' => $now,
            'modified' => $now,
            'parent' => $parent,
            'status' => $parent > 0 ? 'hidden' : 'publish',
            'text' => json_encode($upload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function formatPost(array $row, bool $includeContent, bool $includeMetas): array
    {
        $isDraft = $row['type'] === 'post_draft';
        $result = [
            'cid' => (int)$row['cid'],
            'title' => html_entity_decode((string)$row['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'slug' => (string)$row['slug'],
            'status' => $isDraft ? 'draft' : (string)$row['status'],
            'format' => str_starts_with((string)$row['text'], '<!--markdown-->') ? 'markdown' : 'html',
            'created' => $this->formatTime((int)$row['created']),
            'modified' => $this->formatTime((int)$row['modified']),
            'comments_num' => (int)$row['commentsNum'],
            'allow_comment' => (bool)$row['allowComment'],
            'allow_ping' => (bool)$row['allowPing'],
            'allow_feed' => (bool)$row['allowFeed'],
            'public_url' => (!$isDraft && $row['status'] === 'publish') ? $this->postUrl($row) : null,
        ];

        $content = (string)$row['text'];
        if ($includeContent) {
            $result['content'] = preg_replace('/^<!--markdown-->/', '', $content);
        } else {
            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags(
                preg_replace('/^<!--markdown-->/', '', $content)
            )));
            $result['excerpt'] = Common::subStr($plain, 0, 240, '…');
        }

        if ($includeMetas) {
            $metas = $this->postMetas((int)$row['cid']);
            $result['categories'] = $metas['categories'];
            $result['tags'] = $metas['tags'];
            $result['theme'] = $this->readThemeFields((int)$row['cid']);
        }
        return $result;
    }

    private function themeFieldDefinitions(): array
    {
        return [
            'custom_summary' => [
                'db' => 'customSummary',
                'default' => '',
                'type' => 'string',
                'max' => 20000,
            ],
            'hero_display' => [
                'db' => 'thumbChoice',
                'default' => 'default',
                'type' => 'enum',
                'map' => [
                    'default' => 'default',
                    'both' => 'yes',
                    'index_only' => 'yes_only_index',
                    'post_only' => 'yes_only_post',
                    'none' => 'no',
                ],
            ],
            'hero_image' => [
                'db' => 'thumb',
                'default' => '',
                'type' => 'string',
                'max' => 2048,
            ],
            'hero_image_small' => [
                'db' => 'thumbSmall',
                'default' => '',
                'type' => 'string',
                'max' => 2048,
            ],
            'hero_image_credit' => [
                'db' => 'thumbDesc',
                'default' => '',
                'type' => 'string',
                'max' => 1000,
            ],
            'hero_style' => [
                'db' => 'thumbStyle',
                'default' => 'default',
                'type' => 'enum',
                'map' => [
                    'default' => 'default',
                    'large' => 'large',
                    'small' => 'small',
                    'picture' => 'picture',
                ],
            ],
            'badge_style' => [
                'db' => 'noThumbInfoStyle',
                'default' => 'default',
                'type' => 'enum',
                'map' => [
                    'default' => 'default',
                    'book' => 'book',
                    'game' => 'game',
                    'note' => 'note',
                    'chat' => 'chat',
                    'code' => 'code',
                    'image' => 'image',
                    'web' => 'web',
                    'link' => 'link',
                    'design' => 'design',
                    'lock' => 'lock',
                ],
            ],
            'badge_emoji' => [
                'db' => 'noThumbInfoEmoji',
                'default' => '',
                'type' => 'string',
                'max' => 64,
            ],
            'outdated_notice' => [
                'db' => 'outdatedNotice',
                'default' => false,
                'type' => 'boolean',
                'map' => [false => 'no', true => 'yes'],
            ],
            'reprint_rule' => [
                'db' => 'reprint',
                'default' => 'standard',
                'type' => 'enum',
                'map' => [
                    'standard' => 'standard',
                    'paid' => 'pay',
                    'forbidden' => 'forbidden',
                    'source_site' => 'trans',
                    'internet' => 'internet',
                ],
            ],
            'mathjax' => [
                'db' => 'mathjax',
                'default' => 'auto',
                'type' => 'enum',
                'map' => [
                    'auto' => 'auto',
                    'enabled' => 'true',
                    'disabled' => 'false',
                ],
            ],
            'markdown_parser' => [
                'db' => 'parseWay',
                'default' => 'auto',
                'type' => 'enum',
                'map' => [
                    'auto' => 'auto',
                    'origin' => 'origin',
                    'vditor' => 'vditor',
                ],
            ],
        ];
    }

    private function normalizeThemeFields(array $args, bool $withDefaults): array
    {
        $values = [];
        foreach ($this->themeFieldDefinitions() as $input => $definition) {
            if (!array_key_exists($input, $args)) {
                if (!$withDefaults) {
                    continue;
                }
                $value = $definition['default'];
            } else {
                $value = $args[$input];
            }

            if ($definition['type'] === 'string') {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException($input . ' 必须是字符串');
                }
                if (mb_strlen($value, 'UTF-8') > $definition['max']) {
                    throw new \InvalidArgumentException($input . ' 内容过长');
                }
                $stored = $value;
            } elseif ($definition['type'] === 'boolean') {
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException($input . ' 必须是布尔值');
                }
                $stored = $value ? $definition['map'][true] : $definition['map'][false];
            } else {
                if (!is_string($value) || !array_key_exists($value, $definition['map'])) {
                    throw new \InvalidArgumentException($input . ' 参数无效');
                }
                $stored = $definition['map'][$value];
            }

            $values[$definition['db']] = $stored;
        }
        return $values;
    }

    private function saveThemeFields(int $cid, array $fields): void
    {
        foreach ($fields as $name => $value) {
            $existing = $this->db->fetchRow(
                $this->db->select('cid')->from('table.fields')
                    ->where('cid = ?', $cid)
                    ->where('name = ?', $name)
                    ->limit(1)
            );
            $rows = [
                'type' => 'str',
                'str_value' => (string)$value,
                'int_value' => 0,
                'float_value' => 0,
            ];
            if ($existing) {
                $this->db->query(
                    $this->db->update('table.fields')->rows($rows)
                        ->where('cid = ?', $cid)
                        ->where('name = ?', $name)
                );
            } else {
                $rows['cid'] = $cid;
                $rows['name'] = $name;
                $this->db->query($this->db->insert('table.fields')->rows($rows));
            }
        }
    }

    private function readThemeFields(int $cid): array
    {
        $definitions = $this->themeFieldDefinitions();
        $dbToInput = [];
        $result = [];
        foreach ($definitions as $input => $definition) {
            $dbToInput[$definition['db']] = $input;
            $result[$input] = $definition['default'];
        }

        $rows = $this->db->fetchAll(
            $this->db->select('name', 'str_value')->from('table.fields')
                ->where('cid = ?', $cid)
                ->where('name IN ?', array_keys($dbToInput))
        );
        foreach ($rows as $row) {
            $input = $dbToInput[$row['name']];
            $definition = $definitions[$input];
            $stored = (string)$row['str_value'];
            if ($definition['type'] === 'string') {
                $result[$input] = $stored;
            } elseif ($definition['type'] === 'boolean') {
                $result[$input] = $stored === 'yes';
            } else {
                $external = array_search($stored, $definition['map'], true);
                $result[$input] = $external === false ? $stored : $external;
            }
        }
        return $result;
    }

    private function postMetas(int $cid): array
    {
        $rows = $this->db->fetchAll(
            $this->db->select('table.metas.mid', 'table.metas.name', 'table.metas.slug', 'table.metas.type')
                ->from('table.metas')
                ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
                ->where('table.relationships.cid = ?', $cid)
                ->where('table.metas.type IN ?', ['category', 'tag'])
                ->order('table.metas.type', Db::SORT_ASC)
        );
        $result = ['categories' => [], 'tags' => []];
        foreach ($rows as $row) {
            $item = [
                'mid' => (int)$row['mid'],
                'name' => $row['name'],
                'slug' => $row['slug'],
            ];
            $result[$row['type'] === 'category' ? 'categories' : 'tags'][] = $item;
        }
        return $result;
    }

    private function attachmentRows($select): array
    {
        $rows = $this->db->fetchAll($select);
        return array_map([$this, 'formatAttachment'], $rows);
    }

    private function formatAttachment(array $row): array
    {
        $attachment = json_decode((string)$row['text'], true);
        if (!is_array($attachment)) {
            $attachment = [];
        }
        $config = new Config($attachment);
        $url = !empty($attachment['path']) ? Upload::attachmentHandle($config) : null;
        $type = strtolower((string)($attachment['type'] ?? ''));
        return [
            'cid' => (int)$row['cid'],
            'name' => html_entity_decode((string)($attachment['name'] ?? $row['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'type' => $type,
            'mime' => (string)($attachment['mime'] ?? ''),
            'size' => (int)($attachment['size'] ?? 0),
            'is_image' => in_array($type, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif'], true),
            'url' => $url,
            'parent_cid' => (int)$row['parent'],
            'status' => (string)$row['status'],
            'created' => $this->formatTime((int)$row['created']),
        ];
    }

    private function replaceMetas(
        int $cid,
        array $categoryIds,
        array $tagNames,
        bool $replaceCategories,
        bool $replaceTags
    ): void {
        $affected = [];

        if ($replaceCategories) {
            $categoryIds = array_values(array_unique($categoryIds));
            if (empty($categoryIds)) {
                $categoryIds = [(int)$this->options->defaultCategory];
            }
            foreach ($categoryIds as $mid) {
                $row = $this->db->fetchRow(
                    $this->db->select('mid')->from('table.metas')
                        ->where('mid = ?', $mid)->where('type = ?', 'category')->limit(1)
                );
                if (!$row) {
                    throw new \InvalidArgumentException('分类不存在: ' . $mid);
                }
            }
            $affected = array_merge($affected, $this->relationMids($cid, 'category'));
            $this->deleteRelationsByType($cid, 'category');
            foreach ($categoryIds as $mid) {
                $this->insertRelation($cid, $mid);
                $affected[] = $mid;
            }
        }

        if ($replaceTags) {
            $affected = array_merge($affected, $this->relationMids($cid, 'tag'));
            $this->deleteRelationsByType($cid, 'tag');
            foreach (array_values(array_unique($tagNames)) as $name) {
                $mid = $this->findOrCreateTag($name);
                $this->insertRelation($cid, $mid);
                $affected[] = $mid;
            }
        }

        $this->refreshMetaCounts(array_values(array_unique(array_map('intval', $affected))));
    }

    private function relationMids(int $cid, string $type): array
    {
        return array_map('intval', array_column(
            $this->db->fetchAll(
                $this->db->select('table.metas.mid')->from('table.metas')
                    ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
                    ->where('table.relationships.cid = ?', $cid)
                    ->where('table.metas.type = ?', $type)
            ),
            'mid'
        ));
    }

    private function deleteRelationsByType(int $cid, string $type): void
    {
        $mids = $this->relationMids($cid, $type);
        if (!empty($mids)) {
            $this->db->query(
                $this->db->delete('table.relationships')
                    ->where('cid = ?', $cid)
                    ->where('mid IN ?', $mids)
            );
        }
    }

    private function insertRelation(int $cid, int $mid): void
    {
        $exists = $this->db->fetchRow(
            $this->db->select('cid')->from('table.relationships')
                ->where('cid = ?', $cid)->where('mid = ?', $mid)->limit(1)
        );
        if (!$exists) {
            $this->db->query(
                $this->db->insert('table.relationships')->rows(['cid' => $cid, 'mid' => $mid])
            );
        }
    }

    private function findOrCreateTag(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('标签名称不能为空');
        }
        $row = $this->db->fetchRow(
            $this->db->select('mid')->from('table.metas')
                ->where('type = ?', 'tag')->where('name = ?', $name)->limit(1)
        );
        if ($row) {
            return (int)$row['mid'];
        }

        $slug = Common::slugName($name, 'tag');
        $base = $slug;
        $suffix = 1;
        while ($this->db->fetchRow(
            $this->db->select('mid')->from('table.metas')
                ->where('type = ?', 'tag')->where('slug = ?', $slug)->limit(1)
        )) {
            $slug = $base . '-' . $suffix++;
        }

        return (int)$this->db->query(
            $this->db->insert('table.metas')->rows([
                'name' => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'slug' => $slug,
                'type' => 'tag',
                'description' => null,
                'count' => 0,
                'order' => 0,
                'parent' => 0,
            ])
        );
    }

    private function refreshRelatedMetaCounts(int $cid): void
    {
        $mids = array_map('intval', array_column(
            $this->db->fetchAll(
                $this->db->select('mid')->from('table.relationships')->where('cid = ?', $cid)
            ),
            'mid'
        ));
        $this->refreshMetaCounts($mids);
    }

    private function refreshMetaCounts(array $mids): void
    {
        foreach (array_values(array_unique($mids)) as $mid) {
            if ($mid <= 0) {
                continue;
            }
            $count = $this->db->fetchObject(
                $this->db->select(['COUNT(table.contents.cid)' => 'num'])
                    ->from('table.contents')
                    ->join('table.relationships', 'table.contents.cid = table.relationships.cid')
                    ->where('table.relationships.mid = ?', $mid)
                    ->where('table.contents.type = ?', 'post')
                    ->where('table.contents.status = ?', 'publish')
            )->num;
            $this->db->query(
                $this->db->update('table.metas')->rows(['count' => (int)$count])->where('mid = ?', $mid)
            );
        }
    }

    private function applySlug(string $slug, int $cid, string $title): string
    {
        if ($slug === '' && preg_match_all('/\w+/u', $title, $matches)) {
            $slug = implode('-', $matches[0]);
        }
        $slug = Common::slugName($slug, (string)$cid);
        $base = $slug;
        $suffix = 1;
        while ($this->db->fetchRow(
            $this->db->select('cid')->from('table.contents')
                ->where('slug = ? AND cid <> ?', $slug, $cid)->limit(1)
        )) {
            $slug = $base . '-' . $suffix++;
        }
        $this->db->query(
            $this->db->update('table.contents')->rows(['slug' => $slug])->where('cid = ?', $cid)
        );
        return $slug;
    }

    private function postUrl(array $row): ?string
    {
        try {
            return Router::url('post', [
                'cid' => (int)$row['cid'],
                'slug' => $row['slug'],
                'year' => date('Y', (int)$row['created']),
                'month' => date('m', (int)$row['created']),
                'day' => date('d', (int)$row['created']),
                'category' => '',
            ], $this->options->index);
        } catch (\Throwable $e) {
            return rtrim($this->options->siteUrl, '/') . '/archives/' . (int)$row['cid'] . '/';
        }
    }

    private function prepareContent(string $content, string $format): string
    {
        $content = preg_replace('/^<!--markdown-->/', '', $content);
        return $format === 'markdown' ? '<!--markdown-->' . $content : $content;
    }

    private function normalizeStatus($status): string
    {
        $status = (string)$status;
        if (!in_array($status, ['draft', 'publish', 'private', 'hidden', 'waiting'], true)) {
            throw new \InvalidArgumentException('status 参数无效');
        }
        return $status;
    }

    private function normalizeFormat($format): string
    {
        $format = (string)$format;
        if (!in_array($format, ['markdown', 'html'], true)) {
            throw new \InvalidArgumentException('format 参数无效');
        }
        return $format;
    }

    private function storageStatus(string $status): array
    {
        return $status === 'draft' ? ['post_draft', 'publish'] : ['post', $status];
    }

    private function maxUploadMb(): int
    {
        $value = (int)($this->settings->maxUploadMb ?? 10);
        return max(1, min(50, $value));
    }

    private function defaultAuthorId(): int
    {
        $row = $this->db->fetchRow(
            $this->db->select('uid')->from('table.users')->order('uid', Db::SORT_ASC)->limit(1)
        );
        if (!$row) {
            throw new \RuntimeException('找不到可用的 Typecho 用户');
        }
        return (int)$row['uid'];
    }

    private function positiveInt(array $args, string $name): int
    {
        if (!array_key_exists($name, $args)) {
            throw new \InvalidArgumentException('缺少参数: ' . $name);
        }
        return $this->boundedInt($args[$name], 1, PHP_INT_MAX, $name);
    }

    private function boundedInt($value, int $min, int $max, string $name): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException($name . ' 必须是整数');
        }
        $value = (int)$value;
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException($name . ' 超出允许范围');
        }
        return $value;
    }

    private function intArray($value, string $name): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException($name . ' 必须是数组');
        }
        return array_map(function ($item) use ($name) {
            return $this->boundedInt($item, 1, PHP_INT_MAX, $name);
        }, $value);
    }

    private function stringArray($value, string $name): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException($name . ' 必须是数组');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new \InvalidArgumentException($name . ' 只能包含非空字符串');
            }
            $result[] = trim($item);
        }
        return $result;
    }

    private function boolInt($value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }

    private function formatTime(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function authenticate(): bool
    {
        $expected = trim((string)($this->settings->token ?? ''));
        if ($expected === '') {
            return false;
        }

        $authorization = '';
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key])) {
                $authorization = (string)$_SERVER[$key];
                break;
            }
        }
        if ($authorization === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authorization = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }

        $provided = '';
        if (preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches)) {
            $provided = trim($matches[1]);
        } elseif (!empty($_SERVER['HTTP_X_MCP_TOKEN'])) {
            $provided = trim((string)$_SERVER['HTTP_X_MCP_TOKEN']);
        }

        return $provided !== '' && hash_equals($expected, $provided);
    }

    private function setCommonHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
    }

    private function sendHttpError(int $status, string $message): void
    {
        $this->sendJsonRpcError(null, -32000, $message, $status);
    }

    private function sendJsonRpcError($id, int $code, string $message, int $status): void
    {
        $this->response->setStatus($status);
        http_response_code($status);
        $this->sendPayload($this->errorResponse($id, $code, $message));
    }

    private function sendPayload($payload): void
    {
        echo $this->encode($payload);
        exit;
    }

    private function resultResponse($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function errorResponse($id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    private function toolError(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }

    private function encode($value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode($value, $flags);
        return $json === false ? '{"error":"JSON encode failed"}' : $json;
    }

    private function isList(array $value): bool
    {
        return $value !== [] && array_keys($value) === range(0, count($value) - 1);
    }
}
