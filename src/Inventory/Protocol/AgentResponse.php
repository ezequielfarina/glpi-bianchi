<?php

declare(strict_types=1);

namespace App\Inventory\Protocol;

use Symfony\Component\HttpFoundation\Response;

/**
 * Resposta ao agente, serializada no mesmo modo/compressão da requisição.
 *
 *  JSON: {"status":"ok","expiration":24,"tasks":{"inventory":{"server":"glpi-bianchi","version":"0.1.0"}}}
 *  XML : <?xml version="1.0"?><REPLY><PROLOG_FREQ>24</PROLOG_FREQ><RESPONSE>SEND</RESPONSE></REPLY>
 */
final readonly class AgentResponse
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private array $payload,
        private PayloadFormat $format,
        private Compression $compression,
        private int $status = Response::HTTP_OK,
    ) {
    }

    public function toHttpResponse(): Response
    {
        $body = PayloadFormat::Xml === $this->format
            ? self::toXml($this->payload)
            : json_encode($this->payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return new Response(
            $this->compression->compress($body),
            $this->status,
            ['Content-Type' => $this->compression->contentType($this->format)],
        );
    }

    /** @param array<string, mixed> $payload */
    private static function toXml(array $payload): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('REPLY');
        $doc->appendChild($root);
        self::appendNodes($doc, $root, $payload);

        return trim((string) $doc->saveXML());
    }

    /** @param array<array-key, mixed> $data */
    private static function appendNodes(\DOMDocument $doc, \DOMElement $parent, array $data): void
    {
        foreach ($data as $name => $value) {
            $node = $doc->createElement(strtoupper((string) $name));
            if (\is_array($value)) {
                self::appendNodes($doc, $node, $value);
            } else {
                $node->appendChild($doc->createTextNode((string) $value));
            }
            $parent->appendChild($node);
        }
    }
}
