<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Settings\Service\Settings;
use App\Ui\Write\ValidationException;

/**
 * Etiqueta de envio impressa direto na Argox pela rede (ADR-0014).
 *
 * A etiqueta é desenhada no servidor como imagem 1 bit (GD + TTF), no layout do modelo Entrega_TI, e enviada
 * como gráfico na linguagem da impressora (PPLB `GW` ou ZPL/PPLZ `^GFA`) para a porta RAW (9100). Assim não
 * depende de driver, de diálogo de impressão nem das fontes internas da impressora (acentos e logo saem iguais).
 */
final readonly class LabelPrinter
{
    private const array FONT_CANDIDATES = [
        '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    ];

    public function __construct(
        private Settings $settings,
        private string $logoPath,
    ) {
    }

    /** @return array{configured: bool, host: string, port: int, language: string, dpi: int, width_mm: int, height_mm: int, sender: string} */
    public function config(): array
    {
        $host = trim((string) $this->settings->get(Settings::LABEL_PRINTER_HOST));

        return [
            'configured' => '' !== $host,
            'host' => $host,
            'port' => (int) $this->settings->get(Settings::LABEL_PRINTER_PORT),
            'language' => (string) $this->settings->get(Settings::LABEL_PRINTER_LANGUAGE),
            'dpi' => (int) $this->settings->get(Settings::LABEL_PRINTER_DPI),
            'width_mm' => (int) $this->settings->get(Settings::LABEL_WIDTH_MM),
            'height_mm' => (int) $this->settings->get(Settings::LABEL_HEIGHT_MM),
            'sender' => (string) $this->settings->get(Settings::LABEL_SENDER),
        ];
    }

    /**
     * Imprime N volumes (uma etiqueta por volume, "VOLUME i/N" quando N > 1).
     *
     * @param array{sender?: string, destination: string, department: string, attention: string} $f
     *
     * @return array{printed: int, printer: string, bytes: int, ms: int}
     */
    public function print(array $f, int $volumes): array
    {
        $c = $this->config();
        if (!$c['configured']) {
            throw new ValidationException(['printer' => 'Impressora de etiquetas não configurada (Configurações → Impressora de etiquetas: IP).']);
        }
        $volumes = max(1, min(50, $volumes));
        $job = '';
        for ($i = 1; $i <= $volumes; ++$i) {
            $img = $this->render($f, $volumes > 1 ? "VOLUME $i/$volumes" : null);
            $job .= 'zpl' === $c['language'] ? self::zpl($img) : self::pplb($img);
            imagedestroy($img);
        }
        $t = microtime(true);
        $this->send($c['host'], $c['port'], $job);

        return ['printed' => $volumes, 'printer' => $c['host'].':'.$c['port'], 'bytes' => \strlen($job), 'ms' => (int) round((microtime(true) - $t) * 1000)];
    }

    /**
     * Prévia exata do que vai para a impressora (PNG 1 bit).
     *
     * @param array{sender?: string, destination: string, department: string, attention: string} $f
     */
    public function png(array $f, ?string $volume = null): string
    {
        $img = $this->render($f, $volume);
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /**
     * Desenha a etiqueta (layout do modelo 105 × 75: remetente/destinatário no topo, filial grande, "setor A/C
     * pessoa", logo no canto inferior direito, volume no inferior esquerdo) e reduz a 1 bit.
     *
     * @param array{sender?: string, destination: string, department: string, attention: string} $f
     */
    public function render(array $f, ?string $volume = null): \GdImage
    {
        $c = $this->config();
        $dpmm = $c['dpi'] / 25.4;
        $w = (int) round($c['width_mm'] * $dpmm);
        $h = (int) round($c['height_mm'] * $dpmm);
        $u = min($c['width_mm'] / 105, $c['height_mm'] / 75) * $dpmm;   // 1 "mm do modelo" em pontos
        $font = $this->font();
        $img = imagecreatetruecolor($w, $h) ?: throw new \RuntimeException('GD: falha ao criar a imagem.');
        $white = (int) imagecolorallocate($img, 255, 255, 255);
        $black = (int) imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $w, $h, $white);

        $up = static fn (string $s): string => mb_strtoupper(trim($s));
        $sender = $up($f['sender'] ?? '') ?: $up($c['sender']) ?: 'TI';
        $dest = $up($f['destination']) ?: '—';
        $ac = trim(($up($f['department']) ? $up($f['department']).' ' : '').'A/C '.$up($f['attention']));
        if ('A/C' === $ac) {
            $ac = '';
        }
        $margin = 3 * $u;
        $maxW = $w - 2 * $margin;
        $y = 4 * $u;
        $y = $this->centered($img, $font, 3.9 * $u, "REMETENTE: $sender", $y, $w, $maxW, $black);
        $y = $this->centered($img, $font, 3.9 * $u, 'DESTINATÁRIO:', $y + 0.6 * $u, $w, $maxW, $black);
        $y = $this->centered($img, $font, 10.6 * $u, $dest, $y + 3.5 * $u, $w, $maxW, $black);
        if ('' !== $ac) {
            $this->centered($img, $font, 6.0 * $u, $ac, $y + 1.2 * $u, $w, $maxW, $black);
        }
        if (null !== $volume) {
            imagettftext($img, self::pt(3.2 * $u), 0, (int) $margin, (int) ($h - $margin), $black, $font, $volume);
        }
        if (is_file($this->logoPath) && ($logo = imagecreatefrompng($this->logoPath))) {
            $lw = (int) round(31 * $u);
            $lh = (int) round(imagesy($logo) * $lw / max(1, imagesx($logo)));
            imagealphablending($img, true);
            imagecopyresampled($img, $logo, (int) ($w - $margin - $lw), (int) ($h - $margin - $lh), 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
        }

        return self::monochrome($img);
    }

    /** Texto centralizado numa linha; encolhe a fonte até caber na largura. Retorna o y da linha de base seguinte. */
    private function centered(\GdImage $img, string $font, float $sizeDots, string $text, float $top, int $w, float $maxW, int $color): float
    {
        $pt = self::pt($sizeDots);
        for ($i = 0; $i < 40; ++$i) {
            $box = imagettfbbox($pt, 0, $font, $text) ?: [0, 0, 0, 0, 0, 0, 0, 0];
            if ($box[2] - $box[0] <= $maxW || $pt <= 6) {
                break;
            }
            $pt *= 0.94;
        }
        $box = imagettfbbox($pt, 0, $font, 'ÁÇg') ?: [0, 0, 0, 0, 0, 0, 0, 0];
        $ascent = -$box[7];
        $tbox = imagettfbbox($pt, 0, $font, $text) ?: [0, 0, 0, 0, 0, 0, 0, 0];
        $x = (int) round(($w - ($tbox[2] - $tbox[0])) / 2 - $tbox[0]);
        $baseline = (int) round($top + $ascent);
        imagettftext($img, $pt, 0, $x, $baseline, $color, $font, $text);

        return $baseline + $box[1];
    }

    /** Altura em pontos de impressão → tamanho GD (GD usa "pt" a 96 dpi: 1 pt = 96/72 px). */
    private static function pt(float $dots): float
    {
        return $dots * 72 / 96;
    }

    private static function monochrome(\GdImage $img): \GdImage
    {
        imagefilter($img, \IMG_FILTER_GRAYSCALE);
        $w = imagesx($img);
        $h = imagesy($img);
        $out = imagecreate($w, $h) ?: throw new \RuntimeException('GD: falha ao criar a imagem.');
        $white = (int) imagecolorallocate($out, 255, 255, 255);
        $black = (int) imagecolorallocate($out, 0, 0, 0);
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                imagesetpixel($out, $x, $y, (imagecolorat($img, $x, $y) & 0xFF) < 140 ? $black : $white);
            }
        }
        imagedestroy($img);

        return $out;
    }

    /** Linhas da imagem como bytes (bit 1 = preto, MSB primeiro). @return list<string> */
    private static function rows(\GdImage $img): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $bpr = intdiv($w + 7, 8);
        $rows = [];
        for ($y = 0; $y < $h; ++$y) {
            $bytes = array_fill(0, $bpr, 0);
            for ($x = 0; $x < $w; ++$x) {
                $rgb = imagecolorsforindex($img, imagecolorat($img, $x, $y));
                if ($rgb['red'] < 128) {
                    $bytes[$x >> 3] |= 0x80 >> ($x & 7);
                }
            }
            $rows[] = pack('C*', ...$bytes);
        }

        return $rows;
    }

    /** PPLB (compatível EPL2): gráfico `GW` — no PPLB o bit 0 imprime (preto), então os bits são invertidos. */
    public static function pplb(\GdImage $img): string
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $bpr = intdiv($w + 7, 8);
        $data = implode('', array_map(static fn (string $r): string => ~$r, self::rows($img)));

        return "\nN\nq$w\nQ$h,24\nS3\nD10\nGW0,0,$bpr,$h,".$data."\nP1\n";
    }

    /** ZPL II / PPLZ: gráfico `^GFA` em hexadecimal (bit 1 = preto). */
    public static function zpl(\GdImage $img): string
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $bpr = intdiv($w + 7, 8);
        $hex = strtoupper(bin2hex(implode('', self::rows($img))));
        $total = $bpr * $h;

        return "^XA^PW$w^LL$h^LH0,0^FO0,0^GFA,$total,$total,$bpr,$hex^FS^PQ1^XZ\n";
    }

    private function send(string $host, int $port, string $job): void
    {
        if (!preg_match('/^[A-Za-z0-9.:-]+$/', $host)) {
            throw new ValidationException(['printer' => 'Endereço da impressora inválido.']);
        }
        $target = str_contains($host, ':') && !str_starts_with($host, '[') ? "[$host]" : $host;
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client("tcp://$target:$port", $errno, $errstr, 4.0);
        if (false === $sock) {
            throw new ValidationException(['printer' => \sprintf('Não foi possível conectar à impressora %s:%d (%s). Verifique se ela está ligada e se o servidor alcança a porta %d.', $host, $port, $errstr ?: "erro $errno", $port)]);
        }
        stream_set_timeout($sock, 10);
        $len = \strlen($job);
        for ($off = 0; $off < $len;) {
            $n = @fwrite($sock, substr($job, $off, 65536));
            if (false === $n || 0 === $n) {
                fclose($sock);
                throw new ValidationException(['printer' => 'A conexão com a impressora caiu durante o envio.']);
            }
            $off += $n;
        }
        fclose($sock);
    }

    private function font(): string
    {
        foreach (self::FONT_CANDIDATES as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
        throw new \RuntimeException('Fonte TTF não encontrada (instale fonts-liberation2 ou fonts-dejavu-core).');
    }
}
