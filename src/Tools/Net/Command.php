<?php

declare(strict_types=1);

namespace App\Tools\Net;

/**
 * Executa binários de diagnóstico (ping, mtr, dig, arp-scan...) sem shell: argv em vetor,
 * stdin fechado, saída limitada e tempo máximo. Nunca interpola entrada do usuário em string de shell.
 */
final class Command
{
    private const int MAX_OUTPUT = 2_000_000;

    /**
     * @param list<string> $argv
     *
     * @return array{exit: int, stdout: string, stderr: string, ms: int, timeout: bool}
     */
    public static function run(array $argv, float $timeout = 15.0): array
    {
        $start = microtime(true);
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['LC_ALL' => 'C', 'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin']);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('Não foi possível executar '.$argv[0].'.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $timedOut = false;
        while (true) {
            $r = [$pipes[1], $pipes[2]];
            $w = $e = null;
            if (false === @stream_select($r, $w, $e, 0, 200_000)) {
                break;
            }
            foreach ($r as $p) {
                $chunk = (string) fread($p, 65536);
                if ($p === $pipes[1]) {
                    $out .= $chunk;
                } else {
                    $err .= $chunk;
                }
            }
            if (\strlen($out) > self::MAX_OUTPUT) {
                $out = substr($out, 0, self::MAX_OUTPUT);
                proc_terminate($proc);
                break;
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
            if (microtime(true) - $start > $timeout) {
                $timedOut = true;
                proc_terminate($proc);
                break;
            }
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $timedOut ? 124 : $exit, 'stdout' => $out, 'stderr' => trim($err), 'ms' => (int) round((microtime(true) - $start) * 1000), 'timeout' => $timedOut];
    }

    public static function exists(string $binary): bool
    {
        foreach (['/usr/bin/', '/usr/sbin/', '/bin/', '/sbin/', '/usr/local/bin/'] as $dir) {
            if (is_executable($dir.$binary)) {
                return true;
            }
        }

        return false;
    }
}
