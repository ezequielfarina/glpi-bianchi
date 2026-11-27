<?php

declare(strict_types=1);

namespace App\Settings\Service;

use App\Settings\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Configurações do sistema editáveis pelo painel, com valor padrão vindo do .env.
 *
 * Precedência: valor salvo no banco (app_setting) > padrão do ambiente/definição.
 * Cache em memória por processo; os workers são reciclados (time-limit), então
 * mudanças chegam a eles em no máximo 1 h — e o endpoint do agente (FPM) lê a cada request.
 */
final class Settings
{
    public const string INVENTORY_FREQUENCY_HOURS = 'inventory.frequency_hours';
    public const string AGENT_HTTP_PORT = 'agent.http_port';
    public const string ASSET_INVENTORY_PREFIX = 'asset.inventory_prefix';
    public const string SESSION_IDLE_MINUTES = 'security.session_idle_minutes';
    public const string SESSION_MAX_HOURS = 'security.session_max_hours';
    public const string LABEL_PRINTER_HOST = 'label.printer_host';
    public const string LABEL_PRINTER_PORT = 'label.printer_port';
    public const string LABEL_PRINTER_LANGUAGE = 'label.printer_language';
    public const string LABEL_PRINTER_DPI = 'label.printer_dpi';
    public const string LABEL_WIDTH_MM = 'label.width_mm';
    public const string LABEL_HEIGHT_MM = 'label.height_mm';
    public const string LABEL_SENDER = 'label.sender';

    /**
     * @var array<string, array{label: string, help: string, type: 'int'|'string', min: int, max: int, unit?: string, pattern?: string}>
     */
    public const array DEFINITIONS = [
        self::INVENTORY_FREQUENCY_HOURS => [
            'label' => 'Frequência de inventário',
            'help' => 'Intervalo (horas) informado ao GLPI Agent no campo "expiration". Vale a partir do próximo contato de cada agente.',
            'type' => 'int',
            'min' => 1,
            'max' => 168,
            'unit' => 'horas',
        ],
        self::SESSION_IDLE_MINUTES => [
            'label' => 'Sessão: tempo de inatividade',
            'help' => 'Minutos sem uso até o painel pedir login de novo.',
            'type' => 'int',
            'min' => 5,
            'max' => 480,
            'unit' => 'minutos',
        ],
        self::SESSION_MAX_HOURS => [
            'label' => 'Sessão: duração máxima',
            'help' => 'Horas desde o login até exigir nova autenticação, mesmo em uso contínuo.',
            'type' => 'int',
            'min' => 1,
            'max' => 72,
            'unit' => 'horas',
        ],
        self::ASSET_INVENTORY_PREFIX => [
            'label' => 'Prefixo do patrimônio',
            'help' => 'Prefixo dos números de patrimônio gerados automaticamente (ex.: BIA- → BIA-000123). Não altera números já emitidos.',
            'type' => 'string',
            'min' => 0,
            'max' => 16,
            'pattern' => '/^[A-Za-z0-9._\/-]*$/',
        ],
        // etiqueta de envio impressa direto na Argox pela rede (ADR-0014)
        self::LABEL_PRINTER_HOST => [
            'label' => 'Impressora de etiquetas: IP',
            'help' => 'IP (ou nome) da Argox na rede. O servidor envia a etiqueta direto para ela ao registrar a saída. Vazio = desligado (usa .odt/navegador).',
            'type' => 'string',
            'min' => 0,
            'max' => 64,
            'pattern' => '/^[A-Za-z0-9.:-]*$/',
        ],
        self::LABEL_PRINTER_PORT => [
            'label' => 'Impressora de etiquetas: porta',
            'help' => 'Porta RAW da impressora (Argox/Zebra: 9100).',
            'type' => 'int',
            'min' => 1,
            'max' => 65535,
        ],
        self::LABEL_PRINTER_LANGUAGE => [
            'label' => 'Impressora de etiquetas: linguagem',
            'help' => 'Emulação configurada na Argox (veja a folha de autoteste). PPLA não é suportada — troque a emulação para PPLB ou PPLZ.',
            'type' => 'string',
            'min' => 0,
            'max' => 8,
            'options' => ['pplb' => 'PPLB (padrão Argox)', 'zpl' => 'PPLZ / ZPL'],
        ],
        self::LABEL_PRINTER_DPI => [
            'label' => 'Impressora de etiquetas: resolução',
            'help' => 'Pontos por polegada da cabeça de impressão (OS-214/OS-2140: 203; modelos 300 dpi: 300).',
            'type' => 'int',
            'min' => 150,
            'max' => 600,
            'unit' => 'dpi',
        ],
        self::LABEL_WIDTH_MM => [
            'label' => 'Etiqueta: largura',
            'help' => 'Largura da etiqueta (papel USER do driver: 102,4 mm → 102).',
            'type' => 'int',
            'min' => 30,
            'max' => 120,
            'unit' => 'mm',
        ],
        self::LABEL_HEIGHT_MM => [
            'label' => 'Etiqueta: altura',
            'help' => 'Altura da etiqueta (papel USER do driver: 74,9 mm → 75).',
            'type' => 'int',
            'min' => 20,
            'max' => 200,
            'unit' => 'mm',
        ],
        self::LABEL_SENDER => [
            'label' => 'Etiqueta: remetente',
            'help' => 'Texto de "REMETENTE:" na etiqueta de envio.',
            'type' => 'string',
            'min' => 0,
            'max' => 40,
            'pattern' => '/^[\p{L}\p{N} .,&\/-]*$/u',
        ],
        self::AGENT_HTTP_PORT => [
            'label' => 'Porta HTTP do agente',
            'help' => 'Porta do servidor HTTP embutido do GLPI Agent, usada para "Forçar inventário" (padrão 62354).',
            'type' => 'int',
            'min' => 1,
            'max' => 65535,
        ],
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly int $inventoryFrequencyHours,
    ) {
    }

    public function inventoryFrequencyHours(): int
    {
        return (int) $this->get(self::INVENTORY_FREQUENCY_HOURS);
    }

    public function agentHttpPort(): int
    {
        return (int) $this->get(self::AGENT_HTTP_PORT);
    }

    public function get(string $name): mixed
    {
        return $this->all()[$name]['value'] ?? null;
    }

    /**
     * @return array<string, array{value: mixed, default: mixed, label: string, help: string, type: string, min: int, max: int, unit?: string, options?: array<string, string>, pattern?: string, updatedAt: ?string}>
     */
    public function all(): array
    {
        if (null === $this->cache) {
            $stored = [];
            foreach ($this->em->getRepository(Setting::class)->findAll() as $s) {
                $stored[$s->getName()] = $s;
            }
            $this->cache = [];
            foreach (self::DEFINITIONS as $name => $def) {
                $default = $this->defaultFor($name);
                $this->cache[$name] = $def + [
                    'value' => isset($stored[$name]) ? $stored[$name]->getValue() : $default,
                    'default' => $default,
                    'updatedAt' => isset($stored[$name]) ? $stored[$name]->getUpdatedAt()->format(\DATE_ATOM) : null,
                ];
            }
        }

        return $this->cache;
    }

    /**
     * Valida e grava. Retorna a lista de erros por chave (vazia = sucesso).
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    public function update(array $values): array
    {
        $errors = [];
        /** @var array<string, int|string> $clean */
        $clean = [];
        foreach ($values as $name => $value) {
            $def = self::DEFINITIONS[$name] ?? null;
            if (null === $def) {
                $errors[$name] = 'Configuração desconhecida.';
                continue;
            }
            if ('string' === $def['type'] && isset($def['options'])) {
                $str = \is_scalar($value) ? (string) $value : '';
                if (!\array_key_exists($str, $def['options'])) {
                    $errors[$name] = 'Opção inválida.';
                    continue;
                }
                $clean[$name] = $str;
                continue;
            }
            if ('string' === $def['type']) {
                $str = \is_scalar($value) ? trim((string) $value) : '';
                if (mb_strlen($str) > $def['max'] || 1 !== preg_match($def['pattern'] ?? '/.*/', $str)) {
                    $errors[$name] = \sprintf('Até %d caracteres: letras, números, ponto, hífen, barra ou sublinhado.', $def['max']);
                    continue;
                }
                $clean[$name] = $str;
                continue;
            }
            if (!\is_int($value) && !(\is_string($value) && ctype_digit($value))) {
                $errors[$name] = 'Informe um número inteiro.';
                continue;
            }
            $int = (int) $value;
            if ($int < $def['min'] || $int > $def['max']) {
                $errors[$name] = \sprintf('Valor deve estar entre %d e %d.', $def['min'], $def['max']);
                continue;
            }
            $clean[$name] = $int;
        }

        if ([] !== $errors) {
            return $errors;
        }

        $repo = $this->em->getRepository(Setting::class);
        foreach ($clean as $name => $value) {
            $setting = $repo->find($name);
            if (null === $setting) {
                $this->em->persist(new Setting($name, $value));
            } else {
                $setting->setValue($value);
            }
        }
        $this->em->flush();
        $this->cache = null;

        return [];
    }

    private function defaultFor(string $name): int|string
    {
        return match ($name) {
            self::INVENTORY_FREQUENCY_HOURS => $this->inventoryFrequencyHours,
            self::AGENT_HTTP_PORT => 62354,
            self::ASSET_INVENTORY_PREFIX => 'BIA-',
            self::SESSION_IDLE_MINUTES => 30,
            self::SESSION_MAX_HOURS => 12,
            self::LABEL_PRINTER_HOST => '',
            self::LABEL_PRINTER_PORT => 9100,
            self::LABEL_PRINTER_LANGUAGE => 'pplb',
            self::LABEL_PRINTER_DPI => 203,
            self::LABEL_WIDTH_MM => 102,
            self::LABEL_HEIGHT_MM => 75,
            self::LABEL_SENDER => 'TI',
            default => 0,
        };
    }
}
