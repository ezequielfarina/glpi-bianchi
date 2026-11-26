<?php

declare(strict_types=1);

namespace App\Inventory\Protocol;

/**
 * Requisição do agente já decodificada (descompactada + parse).
 *
 * Dois "modos", como no GLPI (src/Glpi/Agent/Communication/AbstractRequest.php):
 *   - JSON (GLPI Agent ≥ 1.0): {"action": "contact"|"inventory"|..., "deviceid": ..., "content": {...}}
 *   - XML legado (FusionInventory / GLPI Agent em modo compat): <REQUEST><QUERY>PROLOG|INVENTORY</QUERY>...
 */
final readonly class AgentRequest
{
    /**
     * @param array<string, mixed> $data payload JSON completo (XML de inventário já convertido)
     */
    public function __construct(
        public PayloadFormat $format,
        public Compression $compression,
        public string $action,
        public string $deviceId,
        public array $data,
        public int $rawSize,
        public ?string $agentId = null,
    ) {
    }

    /** Agente moderno (header GLPI-Agent-ID) → responde em JSON mesmo a PROLOG XML. */
    public function isModernAgent(): bool
    {
        return null !== $this->agentId;
    }

    public function itemtype(): string
    {
        $itemtype = $this->data['itemtype'] ?? 'Computer';

        return \is_string($itemtype) && '' !== $itemtype ? $itemtype : 'Computer';
    }

    public function isPartial(): bool
    {
        return true === ($this->data['partial'] ?? false);
    }

    /** @return list<string> */
    public function enabledTasks(): array
    {
        $tasks = $this->data['enabled-tasks'] ?? [];

        return \is_array($tasks) ? array_values(array_filter($tasks, 'is_string')) : [];
    }

    public function tag(): ?string
    {
        $tag = $this->data['tag'] ?? $this->data['content']['accountinfo']['keyvalue'] ?? null;

        return \is_string($tag) && '' !== $tag ? $tag : null;
    }
}
