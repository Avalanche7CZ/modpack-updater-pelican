<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Runtime;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Fixed official installer invocation in an unprivileged filesystem/PID sandbox. No unsandboxed fallback. */
class InstallerSandbox
{
    public function java(int $minimum): array
    {
        if (posix_geteuid() === 0 || ! is_file('/usr/bin/bwrap') || fileowner('/usr/bin/bwrap') !== 0 || fileperms('/usr/bin/bwrap') & 0022) {
            throw new RuntimeException('Installer runtime preparation requires an unprivileged queue account and a trusted system bubblewrap sandbox. File-only updates do not require it.');
        }
        $found = [];
        foreach (glob('/usr/lib/jvm/*/bin/java') ?: [] as $path) {
            $path = realpath($path); $home = $path ? dirname(dirname($path)) : '';
            if (! preg_match('~^/usr/lib/jvm/[A-Za-z0-9_.+-]+$~D', $home) || ! is_file($path) || fileowner($path) !== 0 || fileperms($path) & 0022 || fileowner($home) !== 0 || fileperms($home) & 0022) { continue; }
            $process = new Process([$path, '-version'], env: ['JAVA_TOOL_OPTIONS' => false, 'JDK_JAVA_OPTIONS' => false, '_JAVA_OPTIONS' => false]);
            $process->setTimeout(10); $process->run();
            $major = (new \Arcturus\ModpackManager\Services\RuntimeInspector)->javaVersion($process->getErrorOutput().$process->getOutput());
            if ($process->isSuccessful() && $major >= $minimum && in_array($major, [17, 21, 25], true)) { $found[$major] = ['home' => $home, 'major' => $major]; }
        }
        ksort($found);
        if ($found === []) { throw new RuntimeException('No trusted Panel-host Java '.$minimum.'+ is available for isolated installer preparation. Docker image selection alone cannot prepare dependencies on the Panel.'); }
        return reset($found);
    }

    public function command(array $java, string $work, ?string $installer = null): array
    {
        if (is_link($work) || realpath($work) !== $work || fileperms($work) & 0077 || fileowner($work) !== posix_geteuid()) { throw new RuntimeException('Installer workspace is not private or canonical.'); }
        $command = ['/usr/bin/bwrap', '--unshare-user', '--unshare-pid', '--unshare-ipc', '--unshare-uts', '--die-with-parent', '--new-session', '--cap-drop', 'ALL', '--clearenv',
            '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp', '--dir', '/etc', '--bind', $work, '/work', '--ro-bind', $java['home'], $java['home']];
        // Bind only the JDK and system runtime libraries/certificates. No Panel, home, volumes or root bind.
        $mountedPaths = [$java['home']];
        foreach (['/lib/x86_64-linux-gnu', '/lib/aarch64-linux-gnu', '/lib64/ld-linux-x86-64.so.2', '/lib/ld-linux-aarch64.so.1', '/etc/ssl/certs', '/etc/resolv.conf', '/etc/hosts', '/etc/nsswitch.conf'] as $path) {
            if (file_exists($path)) { $mountedPaths[] = $path; $command = [...$command, '--ro-bind', $path, $path]; }
        }
        foreach (glob('/etc/java-*-openjdk') ?: [] as $path) { $mountedPaths[] = $path; $command = [...$command, '--ro-bind', $path, $path]; }
        foreach ($mountedPaths as $mounted) {
            if (function_exists('base_path') && (($panel = realpath(base_path())) === realpath($mounted) || str_starts_with($panel.'/', rtrim(realpath($mounted) ?: $mounted, '/').'/'))) {
                throw new RuntimeException('Panel installation overlaps a required sandbox system mount; installer preparation is blocked.');
            }
        }
        $command = [...$command, '--setenv', 'HOME', '/work', '--setenv', 'PATH', $java['home'].'/bin', '--setenv', 'JAVA_HOME', $java['home'], '--setenv', 'LANG', 'C.UTF-8', '--chdir', '/work'];
        if ($installer === null) { return [...$command, $java['home'].'/bin/java', '-version']; }
        $command = [...$command, '--ro-bind', $installer, '/input/installer.jar'];
        $options = ['-Xmx1024M'];
        // Deliberately configured outbound proxy only; never inherit JVM options, credentials or Panel environment.
        $proxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy');
        if ($proxy) {
            $p = parse_url($proxy);
            if (($p['scheme'] ?? '') !== 'http' || isset($p['user']) || isset($p['pass']) || ! preg_match('/^[A-Za-z0-9_.-]+$/D', $p['host'] ?? '')) {
                throw new RuntimeException('The installer cannot safely use this outbound proxy configuration.');
            }
            foreach (['http', 'https'] as $scheme) { $options[] = '-D'.$scheme.'.proxyHost='.$p['host']; $options[] = '-D'.$scheme.'.proxyPort='.($p['port'] ?? 80); }
        }
        // Installer processors launch child JVMs: only our constructed options are propagated.
        return [...$command, '--setenv', 'JAVA_TOOL_OPTIONS', implode(' ', $options), $java['home'].'/bin/java', '-jar', '/input/installer.jar', '--installServer', '/work'];
    }

    public function run(array $artifact, string $work, int $minimum, ?\Closure $heartbeat = null): int
    {
        $java = $this->java($minimum);
        $probe = new Process($this->command($java, $work)); $probe->setTimeout(15); $probe->run();
        if (! $probe->isSuccessful() || (new \Arcturus\ModpackManager\Services\RuntimeInspector)->javaVersion($probe->getErrorOutput().$probe->getOutput()) !== $java['major']) {
            throw new RuntimeException('The unprivileged installer sandbox is unavailable or cannot run Java. No installer was run outside the sandbox.');
        }
        if (is_link($artifact['path']) || ! hash_equals($artifact['sha256'], hash_file('sha256', $artifact['path']))) { throw new RuntimeException('Installer artifact changed before execution.'); }
        $limit = 'import os,resource,sys; resource.setrlimit(resource.RLIMIT_AS,(8589934592,8589934592)); resource.setrlimit(resource.RLIMIT_NPROC,(128,128)); resource.setrlimit(resource.RLIMIT_CPU,(1800,1800)); resource.setrlimit(resource.RLIMIT_FSIZE,(2147483648,2147483648)); os.execv(sys.argv[1],sys.argv[1:])';
        $process = new Process(['/usr/bin/python3', '-c', $limit, ...$this->command($java, $work, $artifact['path'])]); $process->setTimeout(1800);
        $logPath = $work.'/.arcturus-installer-output.log';
        if (is_link($logPath) || (file_exists($logPath) && ! is_file($logPath))) { throw new RuntimeException('Private installer diagnostic path is unsafe.'); }
        $bytes = is_file($logPath) ? filesize($logPath) : 0;
        $log = fopen($logPath, 'ab');
        if (! $log) { throw new RuntimeException('Cannot create private installer diagnostic log.'); }
        try {
            $process->start(function (string $type, string $chunk) use ($process, $log, &$bytes): void {
                if ($bytes < 2097152) { $part = substr($chunk, 0, 2097152 - $bytes); fwrite($log, $part); $bytes += strlen($part); }
                $process->clearOutput(); $process->clearErrorOutput();
            });
            $last = 0;
            while ($process->isRunning()) { $process->checkTimeout(); if (time() - $last >= 10) { $heartbeat?->__invoke(); $last = time(); } usleep(100000); }
            $process->wait();
            if (! $process->isSuccessful()) { throw new RuntimeException('Official loader installer failed in its private sandbox. Inspect the retained private installer log; no live server files were changed.'); }
        } catch (\Throwable $error) { $process->stop(0); throw $error; }
        finally { fclose($log); }
        return $java['major'];
    }
}
