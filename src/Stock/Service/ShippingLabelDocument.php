<?php

declare(strict_types=1);

namespace App\Stock\Service;

/**
 * Etiqueta de envio em .odt a partir do modelo do usuário (`resources/labels/entrega-ti.odt`, ADR-0014).
 *
 * Por que .odt: a impressão pelo navegador depende de o Chrome casar o tamanho da página com o papel do driver
 * da Argox, o que não é confiável; o LibreOffice já imprime esse modelo certo (10,5 × 7,5 cm → papel USER do
 * driver). O arquivo gerado é o próprio modelo com remetente, filial, setor e A/C preenchidos (as listas
 * suspensas do modelo viram texto fixo); estilos, tamanho de página e logo são os do modelo.
 */
final readonly class ShippingLabelDocument
{
    public function __construct(private string $template)
    {
    }

    /**
     * @param array{sender: string, destination: string, department: string, attention: string} $f
     *
     * @return string conteúdo do .odt
     */
    public function render(array $f): string
    {
        if (!is_file($this->template)) {
            throw new \RuntimeException('Modelo de etiqueta não encontrado: '.$this->template);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'etq');
        if (false === $tmp || !copy($this->template, $tmp)) {
            throw new \RuntimeException('Não foi possível preparar a etiqueta.');
        }
        try {
            $zip = new \ZipArchive();
            if (true !== $zip->open($tmp)) {
                throw new \RuntimeException('Modelo de etiqueta inválido.');
            }
            $xml = $zip->getFromName('content.xml');
            if (false === $xml) {
                throw new \RuntimeException('Modelo de etiqueta sem content.xml.');
            }
            $zip->addFromString('content.xml', self::fill($xml, $f));
            // a miniatura do modelo fica (o manifest a referencia; apagar faz o LibreOffice pedir "reparo")
            $zip->close();

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Preenche o content.xml do modelo Entrega_TI: parágrafo com "REMETENTE:", o da filial (estilo P4, lista
     * suspensa) e o de "setor A/C pessoa" (estilo P5, lista suspensa + nome).
     *
     * @param array{sender: string, destination: string, department: string, attention: string} $f
     */
    public static function fill(string $xml, array $f): string
    {
        $e = static fn (string $s): string => htmlspecialchars(mb_strtoupper(trim($s)), \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
        // remetente: o span logo depois de "REMETENTE:"
        $xml = (string) preg_replace('#(REMETENTE:</text:span>\s*<text:span[^>]*>)[^<]*(</text:span>)#u', '${1}'.$e($f['sender'] ?: 'TI').'${2}', $xml, 1);
        // filial: a 1ª lista suspensa (ou o texto) do parágrafo seguinte a "DESTINATÁRIO:"
        $paragraphs = [];
        preg_match_all('#<text:p\b[^>]*?(?:/>|>.*?</text:p>)#su', $xml, $paragraphs, \PREG_OFFSET_CAPTURE);
        $list = $paragraphs[0];
        $destIdx = null;
        foreach ($list as $i => [$p]) {
            if (str_contains($p, 'DESTINAT')) {
                $destIdx = $i;
                break;
            }
        }
        if (null === $destIdx) {
            throw new \RuntimeException('Modelo de etiqueta sem o campo DESTINATÁRIO.');
        }
        // os dois próximos parágrafos com conteúdo: filial e "setor A/C pessoa"
        $targets = [];
        for ($i = $destIdx + 1; $i < \count($list) && \count($targets) < 2; ++$i) {
            if (!str_ends_with($list[$i][0], '/>') && '' !== trim(strip_tags($list[$i][0]))) {
                $targets[] = $i;
            }
        }
        if (2 !== \count($targets)) {
            throw new \RuntimeException('Modelo de etiqueta fora do formato esperado (filial e A/C).');
        }
        $ac = trim($f['department']) ? $e($f['department']).' A/C' : 'A/C';
        $replacements = [
            $targets[0] => self::replaceBody($list[$targets[0]][0], $e($f['destination'])),
            $targets[1] => self::replaceBody($list[$targets[1]][0], $ac.('' !== trim($f['attention']) ? ' '.$e($f['attention']) : ''), true),
        ];
        krsort($replacements);
        foreach ($replacements as $i => $new) {
            [$old, $offset] = $list[$i];
            $xml = substr_replace($xml, $new, $offset, \strlen($old));
        }

        return $xml;
    }

    /** Troca o conteúdo do parágrafo por texto fixo, mantendo o estilo do parágrafo (e do 1º span, se houver). */
    private static function replaceBody(string $p, string $text, bool $keepSpanStyle = false): string
    {
        preg_match('#^<text:p\b[^>]*>#u', $p, $open);
        $span = $keepSpanStyle && preg_match('#<text:span text:style-name="([^"]+)"#u', $p, $m) ? $m[1] : null;
        $body = null !== $span ? '<text:span text:style-name="'.$span.'">'.$text.'</text:span>' : $text;

        return ($open[0] ?? '<text:p>').$body.'</text:p>';
    }
}
