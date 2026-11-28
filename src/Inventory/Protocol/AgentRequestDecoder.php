<?php

declare(strict_types=1);

namespace App\Inventory\Protocol;

use Glpi\Inventory\Converter;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP Request → AgentRequest.
 *
 * Passos: Content-Type → compressão → descompacta (com limite) → detecta modo
 * (JSON/XML pelo 1º caractere, como o GLPI) → parse → extrai action/deviceid.
 * Inventário XML legado é convertido para o formato JSON oficial pelo
 * Converter do glpi-project/inventory_format.
 */
final readonly class AgentRequestDecoder
{
    public function __construct(
        private Converter $converter,
        private int $maxPayloadBytes,
    ) {
    }

    public function decode(Request $request): AgentRequest
    {
        $body = $request->getContent();
        if ('' === $body) {
            throw new ProtocolException('Empty request body.', 400);
        }

        $compression = Compression::fromContentType($request->headers->get('Content-Type'));
        $raw = $compression->decompress($body, $this->maxPayloadBytes);
        $agentId = $request->headers->get('GLPI-Agent-ID');

        $first = ltrim($raw)[0] ?? '';

        return '<' === $first
            ? $this->decodeXml($raw, $compression, $agentId)
            : $this->decodeJson($raw, $compression, $agentId);
    }

    private function decodeJson(string $raw, Compression $compression, ?string $agentId): AgentRequest
    {
        try {
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException $e) {
            throw new ProtocolException('JSON not well formed!', 400, $e);
        }
        if (!\is_array($data)) {
            throw new ProtocolException('JSON payload must be an object.', 400);
        }

        // "action" (protocolo JSON) tem precedência sobre "query" (compat); padrão: inventory
        $action = $data['action'] ?? $data['query'] ?? 'inventory';
        // endpoint público: tipo errado é erro de protocolo (400), não warning/500 (auditoria 2026-11)
        if (!\is_string($action) || (isset($data['deviceid']) && !\is_string($data['deviceid']))) {
            throw new ProtocolException('Invalid "action" or "deviceid" type.');
        }

        return new AgentRequest(
            format: PayloadFormat::Json,
            compression: $compression,
            action: strtolower((string) $action),
            deviceId: (string) ($data['deviceid'] ?? ''),
            data: $data,
            rawSize: \strlen($raw),
            agentId: $agentId,
        );
    }

    private function decodeXml(string $raw, Compression $compression, ?string $agentId): AgentRequest
    {
        if (false === mb_detect_encoding($raw, 'UTF-8', true)) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($raw, \SimpleXMLElement::class, \LIBXML_NOCDATA | \LIBXML_NONET);
            if (false === $xml) {
                throw new ProtocolException('XML not well formed!', 400);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $action = strtolower(isset($xml->QUERY) ? (string) $xml->QUERY : 'inventory');
        $deviceId = (string) $xml->DEVICEID;

        // PROLOG não tem conteúdo útil; só INVENTORY é convertido para JSON
        $data = ['deviceid' => $deviceId, 'action' => $action];
        if ('inventory' === $action) {
            try {
                $converted = json_decode($this->converter->convert($raw), true, 512, \JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new ProtocolException('Unable to convert legacy XML inventory: '.$e->getMessage(), 400, $e);
            }
            $data = \is_array($converted) ? $converted : $data;
        }

        return new AgentRequest(
            format: PayloadFormat::Xml,
            compression: $compression,
            action: $action,
            deviceId: $deviceId,
            data: $data,
            rawSize: \strlen($raw),
            agentId: $agentId,
        );
    }
}
