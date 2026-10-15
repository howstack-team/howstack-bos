<?php

declare(strict_types=1);

namespace tests\support;

/** PHP 测试调用独立本地 SMTP 进程；只监听 loopback，无外部收件人。 */
final class LocalSmtpServer
{
    public readonly int $port;
    public readonly string $capture;
    private mixed $process;
    private array $pipes;

    /** @param string $mode 服务行为 @param string|null $certificate 本地测试证书 @param string|null $key 临时私钥 */
    public function __construct(string $mode = 'accept', ?string $certificate = null, ?string $key = null)
    {
        $this->capture = sys_get_temp_dir() . '/howstack-smtp-' . bin2hex(random_bytes(6)) . '.json';
        $command = ['python3', dirname(__DIR__) . '/fixtures/smtp/server.py', $mode, $this->capture];
        if ($certificate !== null) {
            $command[] = $certificate;
            $command[] = $key;
        }
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
        $this->pipes = $pipes;
        stream_set_timeout($pipes[1], 5);
        $this->port = (int) fgets($pipes[1]);
        if ($this->port < 1) {
            throw new \RuntimeException('本地 SMTP 测试服务未能启动');
        }
    }

    /** @return list<array<string, mixed>> 测试邮箱中的 MIME 邮件 */
    public function messages(): array
    {
        return is_file($this->capture) ? json_decode(file_get_contents($this->capture), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    public function __destruct()
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->process);
        }
        if (is_file($this->capture)) {
            unlink($this->capture);
        }
    }
}
