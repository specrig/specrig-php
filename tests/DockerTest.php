<?php

declare(strict_types=1);

namespace Specrig\Tests;

use PHPUnit\Framework\TestCase;
use Specrig\Docker;
use Specrig\Environment;

final class DockerTest extends TestCase
{
    private string $directory;

    /** @var resource */
    private $process;

    private Docker $docker;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/specrig-docker-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/docker.php', "{$this->directory}/docker.sock", "{$this->directory}/calls"], [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        fgets($pipes[1]);
        $this->docker = new Docker("{$this->directory}/docker.sock");
    }

    protected function tearDown(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        Environment::unset('TESTCONTAINERS_HOST_OVERRIDE');
        foreach (glob("{$this->directory}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /** @return list<array{method: string, path: string, body: mixed}> */
    private function calls(): array
    {
        return array_map(fn (string $line) => json_decode($line, true), file("{$this->directory}/calls", FILE_IGNORE_NEW_LINES) ?: []);
    }

    /** @return list<list<string>> the labels each call filtered by */
    private function filtered(): array
    {
        return array_map(function (array $call) {
            parse_str((string) parse_url($call['path'], PHP_URL_QUERY), $query);
            return json_decode((string) $query['filters'], true)['label'];
        }, $this->calls());
    }

    public function testAContainerStartedOnTheHostIsPublishedOnLoopbackAndLabelledSo(): void
    {
        $this->docker->run('mysql:8.4', 3306, [], ['specrig' => 'mysql'], 'specrig-mysql');

        $spec = $this->calls()[0]['body'];
        $this->assertSame('127.0.0.1', $spec['HostConfig']['PortBindings']['3306/tcp'][0]['HostIp']);
        $this->assertSame(['specrig' => 'mysql', 'specrig.published' => 'loopback'], $spec['Labels']);
    }

    public function testAContainerStartedFromAContainerIsPublishedEverywhereAndLabelledSo(): void
    {
        Environment::set('TESTCONTAINERS_HOST_OVERRIDE', 'host.docker.internal');

        $this->docker->run('mysql:8.4', 3306, [], ['specrig' => 'mysql'], 'specrig-mysql');

        $spec = $this->calls()[0]['body'];
        $this->assertSame('', $spec['HostConfig']['PortBindings']['3306/tcp'][0]['HostIp']);
        $this->assertSame(['specrig' => 'mysql', 'specrig.published' => 'everywhere'], $spec['Labels']);
    }

    public function testOnlyAContainerPublishedAsThisRunWouldPublishItIsFound(): void
    {
        $this->docker->containers(['specrig' => 'mysql']);
        Environment::set('TESTCONTAINERS_HOST_OVERRIDE', 'host.docker.internal');
        $this->docker->containers(['specrig' => 'mysql']);

        $this->assertSame(['specrig=mysql', 'specrig.published=loopback'], $this->filtered()[0]);
        $this->assertSame(['specrig=mysql', 'specrig.published=everywhere'], $this->filtered()[1]);
    }
}
