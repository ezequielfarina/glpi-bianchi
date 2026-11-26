<?php

declare(strict_types=1);

namespace App\Tools\Scanner;

/**
 * Executa o nmap (proc_open com argv — sem shell) lendo o XML em fluxo:
 * cada `<host>` completo vira um callback (dispositivos aparecem na tela durante a varredura)
 * e `<taskprogress>` alimenta a barra de progresso. Cancelamento: SIGTERM no processo.
 */
class NmapRunner
{
    public function __construct(private readonly string $nmapBinary = '/usr/bin/nmap')
    {
    }

    public function available(): bool
    {
        return is_executable($this->nmapBinary);
    }

    public function version(): ?string
    {
        if (!$this->available()) {
            return null;
        }
        $out = (string) shell_exec(escapeshellarg($this->nmapBinary).' --version 2>/dev/null');

        return 1 === preg_match('/Nmap version (\S+)/', $out, $m) ? $m[1] : null;
    }

    /**
     * @param list<string>                         $args
     * @param callable(\SimpleXMLElement): void   $onHost
     * @param callable(string, float): void        $onProgress tarefa, percentual (-1 = só mudou a fase)
     * @param callable(): bool                     $shouldStop
     *
     * @return array{exit: int, stderr: string, stopped: bool}
     */
    public function run(array $args, string $target, callable $onHost, callable $onProgress, callable $shouldStop, int $timeoutSeconds = 3600): array
    {
        $cmd = [$this->nmapBinary, ...$args, $target];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['LANG' => 'C', 'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('Não foi possível iniciar o nmap.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffer = '';
        $stderr = '';
        $stopped = false;
        $started = time();
        $lastCheck = 0.0;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (false === @stream_select($read, $write, $except, 1)) {
                break;
            }
            foreach ($read as $r) {
                $chunk = (string) fread($r, 65536);
                if ($r === $pipes[1]) {
                    $buffer .= $chunk;
                } elseif (\strlen($stderr) < 20000) {
                    $stderr .= $chunk;
                }
            }
            $buffer = $this->drain($buffer, $onHost, $onProgress);

            if (microtime(true) - $lastCheck >= 1.0) {
                $lastCheck = microtime(true);
                if ($shouldStop() || time() - $started > $timeoutSeconds) {
                    $stopped = true;
                    proc_terminate($proc, 15);
                }
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }
        $this->drain($buffer, $onHost, $onProgress);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'stderr' => trim($stderr), 'stopped' => $stopped];
    }

    /**
     * Consome do buffer, em ordem, os blocos completos (`<taskprogress .../>` e `<host>…</host>`)
     * e devolve o que sobrou (bloco ainda incompleto).
     *
     * @param callable(\SimpleXMLElement): void $onHost
     * @param callable(string, float): void      $onProgress
     */
    private function drain(string $buffer, callable $onHost, callable $onProgress): string
    {
        while (1 === preg_match('/<task(?:progress|begin)\b|<host[ >]/', $buffer, $m, \PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            if (str_starts_with($m[0][0], '<task')) {
                $end = strpos($buffer, '>', $start);
                if (false === $end) {
                    return substr($buffer, $start);
                }
                $tag = substr($buffer, $start, $end - $start + 1);
                if (1 === preg_match('/task="([^"]*)"/', $tag, $t)) {
                    // <taskbegin> só troca a fase (percentual -1); <taskprogress> traz o percentual
                    $onProgress(html_entity_decode($t[1]), 1 === preg_match('/percent="([\d.]+)"/', $tag, $pc) ? (float) $pc[1] : -1.0);
                }
                $buffer = substr($buffer, $end + 1);
                continue;
            }
            $end = strpos($buffer, '</host>', $start);
            if (false === $end) {
                return substr($buffer, $start);
            }
            $xml = substr($buffer, $start, $end + 7 - $start);
            $buffer = substr($buffer, $end + 7);
            $prev = libxml_use_internal_errors(true);
            $node = simplexml_load_string($xml, \SimpleXMLElement::class, \LIBXML_NONET);
            libxml_use_internal_errors($prev);
            if (false !== $node) {
                $onHost($node);
            }
        }

        // nada pendente: guarda só a cauda (pode haver "<taskpro" cortado)
        return substr($buffer, max(0, \strlen($buffer) - 64));
    }
}
