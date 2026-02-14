<?php
namespace TypechoPlugin\SendMail;

/**
 * 轻量级 SMTP 邮件发送类
 * 纯 socket 实现，不依赖任何第三方库
 *
 * @package SendMail
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class Smtp
{
    private $host;
    private $port;
    private $secure;
    private $username;
    private $password;
    private $debug;
    private $socket;
    private $logs = array();

    /**
     * @param string $host     SMTP 服务器地址
     * @param int    $port     SMTP 端口
     * @param string $secure   加密方式: ssl / tls / none
     * @param string $username 用户名
     * @param string $password 密码/授权码
     * @param bool   $debug    调试模式
     */
    public function __construct($host, $port, $secure, $username, $password, $debug = false)
    {
        $this->host     = $host;
        $this->port     = $port;
        $this->secure   = strtolower($secure);
        $this->username = $username;
        $this->password = $password;
        $this->debug    = $debug;
    }

    /**
     * 发送邮件
     *
     * @param string $fromEmail 发件人邮箱
     * @param string $fromName  发件人名称
     * @param string $toEmail   收件人邮箱
     * @param string $toName    收件人名称
     * @param string $subject   邮件标题
     * @param string $body      邮件正文（HTML）
     * @return bool
     * @throws \Exception
     */
    public function send($fromEmail, $fromName, $toEmail, $toName, $subject, $body)
    {
        $this->connect();
        $this->ehlo();

        // TLS 需要在 EHLO 之后进行 STARTTLS
        if ($this->secure === 'tls') {
            $this->starttls();
            $this->ehlo();
        }

        $this->authenticate();
        $this->mailFrom($fromEmail);
        $this->rcptTo($toEmail);
        $this->data($fromEmail, $fromName, $toEmail, $toName, $subject, $body);
        $this->quit();

        return true;
    }

    /**
     * 连接到 SMTP 服务器
     */
    private function connect()
    {
        $host = $this->host;

        if ($this->secure === 'ssl') {
            $host = 'ssl://' . $host;
        }

        $errno  = 0;
        $errstr = '';

        $this->socket = @fsockopen($host, $this->port, $errno, $errstr, 30);

        if (!$this->socket) {
            throw new \Exception("无法连接到 SMTP 服务器 {$this->host}:{$this->port} - [{$errno}] {$errstr}");
        }

        // 设置超时
        stream_set_timeout($this->socket, 30);

        $response = $this->getResponse();
        if ($this->getCode($response) !== 220) {
            throw new \Exception("SMTP 服务器连接失败: {$response}");
        }
    }

    /**
     * 发送 EHLO 命令
     */
    private function ehlo()
    {
        $hostname = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
        $response = $this->sendCommand("EHLO {$hostname}");
        if ($this->getCode($response) !== 250) {
            // 尝试 HELO
            $response = $this->sendCommand("HELO {$hostname}");
            if ($this->getCode($response) !== 250) {
                throw new \Exception("EHLO/HELO 命令失败: {$response}");
            }
        }
    }

    /**
     * STARTTLS 加密升级
     */
    private function starttls()
    {
        $response = $this->sendCommand("STARTTLS");
        if ($this->getCode($response) !== 220) {
            throw new \Exception("STARTTLS 命令失败: {$response}");
        }

        $crypto = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$crypto) {
            throw new \Exception("TLS 加密启用失败");
        }
    }

    /**
     * SMTP 认证
     */
    private function authenticate()
    {
        if (empty($this->username) || empty($this->password)) {
            return;
        }

        $response = $this->sendCommand("AUTH LOGIN");
        if ($this->getCode($response) !== 334) {
            throw new \Exception("AUTH LOGIN 命令失败: {$response}");
        }

        $response = $this->sendCommand(base64_encode($this->username));
        if ($this->getCode($response) !== 334) {
            throw new \Exception("SMTP 用户名认证失败: {$response}");
        }

        $response = $this->sendCommand(base64_encode($this->password));
        if ($this->getCode($response) !== 235) {
            throw new \Exception("SMTP 密码认证失败: {$response}");
        }
    }

    /**
     * MAIL FROM 命令
     */
    private function mailFrom($email)
    {
        $response = $this->sendCommand("MAIL FROM:<{$email}>");
        if ($this->getCode($response) !== 250) {
            throw new \Exception("MAIL FROM 命令失败: {$response}");
        }
    }

    /**
     * RCPT TO 命令
     */
    private function rcptTo($email)
    {
        $response = $this->sendCommand("RCPT TO:<{$email}>");
        $code = $this->getCode($response);
        if ($code !== 250 && $code !== 251) {
            throw new \Exception("RCPT TO 命令失败: {$response}");
        }
    }

    /**
     * DATA 命令 - 发送邮件数据
     */
    private function data($fromEmail, $fromName, $toEmail, $toName, $subject, $body)
    {
        $response = $this->sendCommand("DATA");
        if ($this->getCode($response) !== 354) {
            throw new \Exception("DATA 命令失败: {$response}");
        }

        // 构建邮件头
        $headers = array();
        $headers[] = "Date: " . date('r');
        $headers[] = "From: " . $this->encodeHeader($fromName) . " <{$fromEmail}>";
        $headers[] = "To: " . $this->encodeHeader($toName) . " <{$toEmail}>";
        $headers[] = "Subject: " . $this->encodeHeader($subject);
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/html; charset=UTF-8";
        $headers[] = "Content-Transfer-Encoding: base64";
        $headers[] = "X-Mailer: SendMail-Typecho-Plugin/1.0";
        $headers[] = "Message-ID: <" . md5(uniqid(microtime(true))) . "@" . $this->host . ">";
        $headers[] = "";

        // 邮件正文 base64 编码，每76个字符换行
        $encodedBody = chunk_split(base64_encode($body), 76, "\r\n");

        // headers 与 body 之间必须有一个空行（\r\n\r\n）
        $message = implode("\r\n", $headers) . "\r\n" . $encodedBody;

        // 处理单独一行只有 . 的情况（SMTP 透明处理）
        $message = str_replace("\r\n.\r\n", "\r\n..\r\n", $message);

        // 发送数据和结束标记
        $response = $this->sendCommand($message . "\r\n.");
        if ($this->getCode($response) !== 250) {
            throw new \Exception("邮件数据发送失败: {$response}");
        }
    }

    /**
     * QUIT 命令
     */
    private function quit()
    {
        $this->sendCommand("QUIT");
        @fclose($this->socket);
        $this->socket = null;
    }

    /**
     * 发送 SMTP 命令并获取响应
     */
    private function sendCommand($command)
    {
        if ($this->debug) {
            $this->logs[] = "C: " . (
                strpos($command, 'AUTH') !== false && strlen($command) > 20
                    ? substr($command, 0, 20) . '***'
                    : $command
            );
        }

        fwrite($this->socket, $command . "\r\n");

        $response = $this->getResponse();

        if ($this->debug) {
            $this->logs[] = "S: " . $response;
        }

        return $response;
    }

    /**
     * 读取 SMTP 服务器响应
     */
    private function getResponse()
    {
        $response = '';
        $endTime  = time() + 30;

        while (time() < $endTime) {
            $line = @fgets($this->socket, 512);

            if ($line === false) {
                break;
            }

            $response .= $line;

            // 响应结束：第4个字符是空格（而非 -）
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        return trim($response);
    }

    /**
     * 从响应中提取状态码
     */
    private function getCode($response)
    {
        return intval(substr($response, 0, 3));
    }

    /**
     * 编码邮件头（支持中文）
     */
    private function encodeHeader($string)
    {
        if (preg_match('/[^\x20-\x7E]/', $string)) {
            return '=?UTF-8?B?' . base64_encode($string) . '?=';
        }
        return $string;
    }

    /**
     * 获取调试日志
     */
    public function getLogs()
    {
        return $this->logs;
    }

    /**
     * 析构函数，确保连接关闭
     */
    public function __destruct()
    {
        if ($this->socket) {
            @fclose($this->socket);
        }
    }
}
