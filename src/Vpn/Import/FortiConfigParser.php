<?php

declare(strict_types=1);

namespace App\Vpn\Import;

/**
 * Lê a configuração do FortiOS (`show …`, `show full-configuration …` ou o backup .conf inteiro) em árvore:
 *   ['set' => [chave => list<valor>], 'config' => [caminho => nó], 'edit' => [nome => nó]]
 * Strings entre aspas podem ter várias linhas (certificados) e escapes (\"). Linhas `#` são cabeçalho/comentário.
 * O texto nunca é guardado: só a árvore em memória durante a importação (ADR-0022).
 */
final class FortiConfigParser
{
    public const int MAX_BYTES = 8 * 1024 * 1024;

    /** @return array{tree: array<string, mixed>, header: array<string, string>} */
    public function parse(string $text): array
    {
        if (\strlen($text) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Configuração grande demais (limite 8 MiB). Use "show vpn ipsec phase1-interface" + "phase2-interface" em vez do backup inteiro.');
        }
        $header = [];
        $root = self::node();
        /** @var list<array<string, mixed>> $stack */
        $stack = [&$root];
        foreach ($this->lines($text) as $tokens) {
            $cmd = strtolower($tokens[0] ?? '');
            $top = \count($stack) - 1;
            switch ($cmd) {
                case 'config':
                    $path = strtolower(implode(' ', \array_slice($tokens, 1)));
                    $stack[$top]['config'][$path] ??= self::node();
                    $stack[] = &$stack[$top]['config'][$path];
                    break;
                case 'edit':
                    $name = (string) ($tokens[1] ?? '');
                    $stack[$top]['edit'][$name] ??= self::node();
                    $stack[] = &$stack[$top]['edit'][$name];
                    break;
                case 'set':
                    if (isset($tokens[1])) {
                        $stack[$top]['set'][strtolower($tokens[1])] = \array_slice($tokens, 2);
                    }
                    break;
                case 'append':
                    if (isset($tokens[1])) {
                        $key = strtolower($tokens[1]);
                        $stack[$top]['set'][$key] = [...($stack[$top]['set'][$key] ?? []), ...\array_slice($tokens, 2)];
                    }
                    break;
                case 'next':
                case 'end':
                    if ($top > 0) {
                        array_pop($stack);
                    }
                    break;
                default:
                    // prompt da CLI colado junto: "FGT-CVL # show …" / "FGT-DC (DMZ) $ show …"
                    if (!isset($header['prompt']) && 1 === preg_match('/^([A-Za-z0-9._-]+)(?: \([^)]*\))? ?[#$]$/', $tokens[0].(isset($tokens[1]) && \in_array($tokens[1], ['#', '$'], true) ? ' '.$tokens[1] : ''), $m)) {
                        $header['prompt'] = $m[1];
                    }
                    if (str_starts_with($cmd, '#') && 1 === preg_match('/^#([\w-]+)=(.*)$/', implode(' ', $tokens), $m)) {
                        $header[strtolower($m[1])] = trim($m[2]);
                    }
            }
        }

        return ['tree' => $root, 'header' => $header];
    }

    /** @return array{set: array<string, list<string>>, config: array<string, mixed>, edit: array<string, mixed>} */
    private static function node(): array
    {
        return ['set' => [], 'config' => [], 'edit' => []];
    }

    /**
     * Linhas lógicas em tokens: espaço separa, "aspas" agrupam (inclusive quebra de linha), \ escapa dentro das aspas.
     *
     * @return \Generator<int, list<string>>
     */
    private function lines(string $text): \Generator
    {
        $tokens = [];
        $cur = '';
        $inQuote = false;
        $has = false;
        $len = \strlen($text);
        for ($i = 0; $i < $len; ++$i) {
            $c = $text[$i];
            if ($inQuote) {
                if ('\\' === $c && $i + 1 < $len) {
                    $cur .= $text[++$i];
                } elseif ('"' === $c) {
                    $inQuote = false;
                } else {
                    $cur .= $c;
                }
                continue;
            }
            if ('"' === $c) {
                $inQuote = true;
                $has = true;
            } elseif ("\n" === $c || "\r" === $c) {
                if ($has) {
                    $tokens[] = $cur;
                }
                if ([] !== $tokens) {
                    yield $tokens;
                }
                $tokens = [];
                $cur = '';
                $has = false;
            } elseif (' ' === $c || "\t" === $c) {
                if ($has) {
                    $tokens[] = $cur;
                    $cur = '';
                    $has = false;
                }
            } else {
                $cur .= $c;
                $has = true;
            }
        }
        if ($has) {
            $tokens[] = $cur;
        }
        if ([] !== $tokens) {
            yield $tokens;
        }
    }
}
