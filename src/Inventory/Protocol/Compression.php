<?php

declare(strict_types=1);

namespace App\Inventory\Protocol;

/**
 * Compressões aceitas no corpo da requisição, detectadas pelo Content-Type
 * (mesma tabela do GLPI: AbstractRequest::handleContentType()).
 * Brotli fica de fora no MVP (exige extensão PECL); o GLPI Agent usa zlib por padrão.
 */
enum Compression: string
{
    case None = 'none';
    case Zlib = 'zlib';
    case Gzip = 'gzip';
    case Deflate = 'deflate';

    public static function fromContentType(?string $contentType): self
    {
        $type = strtolower(trim(explode(';', (string) $contentType)[0]));

        return match ($type) {
            'application/x-zlib', 'application/x-compress-zlib' => self::Zlib,
            'application/x-gzip', 'application/x-compress-gzip' => self::Gzip,
            'application/x-deflate', 'application/x-compress-deflate' => self::Deflate,
            default => self::None,
        };
    }

    public function contentType(PayloadFormat $format): string
    {
        return match ($this) {
            self::Zlib => 'application/x-compress-zlib',
            self::Gzip => 'application/x-compress-gzip',
            self::Deflate => 'application/x-compress-deflate',
            self::None => PayloadFormat::Xml === $format ? 'application/xml' : 'application/json',
        };
    }

    public function decompress(string $body, int $maxBytes): string
    {
        $result = match ($this) {
            self::None => $body,
            self::Zlib => @gzuncompress($body, $maxBytes),
            self::Gzip => @gzdecode($body, $maxBytes),
            self::Deflate => @gzinflate($body, $maxBytes),
        };

        if (false === $result) {
            throw new ProtocolException(\sprintf('Unable to decompress %s payload (corrupted or larger than %d bytes).', $this->value, $maxBytes), 400);
        }

        return $result;
    }

    public function compress(string $body): string
    {
        $result = match ($this) {
            self::None => $body,
            self::Zlib => gzcompress($body),
            self::Gzip => gzencode($body),
            self::Deflate => gzdeflate($body),
        };

        return false === $result ? $body : $result;
    }
}
