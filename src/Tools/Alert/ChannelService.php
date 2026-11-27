<?php

declare(strict_types=1);

namespace App\Tools\Alert;

use App\Shared\Security\SecretBox;
use App\Tools\Entity\NotificationChannel;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;

/**
 * Canais de notificação (ADR-0013) — só Administrador (security.yaml). O destino (URL do webhook
 * ou token do bot) é cifrado com a DATA_ENCRYPTION_KEY e nunca volta para a tela: só um resumo mascarado.
 */
final readonly class ChannelService
{
    public function __construct(private Connection $db, private SecretBox $box)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map(function (array $r): array {
            try {
                $masked = self::mask((string) $r['kind'], $this->box->decrypt((string) $r['target']));
            } catch (\Throwable) {
                $masked = '(ilegível — DATA_ENCRYPTION_KEY mudou?)';
            }

            return [
                'id' => (int) $r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'target_masked' => $masked, 'chat_id' => $r['chat_id'],
                'min_severity' => $r['min_severity'], 'enabled' => (bool) $r['enabled'], 'last_status' => $r['last_status'],
                'last_error' => $r['last_error'], 'last_sent_at' => $r['last_sent_at'],
            ];
        }, $this->db->fetchAllAssociative('SELECT * FROM notification_channel ORDER BY name'));
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        if (!$this->box->isConfigured()) {
            throw new ValidationException(['target' => 'DATA_ENCRYPTION_KEY não configurada no servidor.']);
        }
        $current = null === $id ? null : ($this->db->fetchAssociative('SELECT * FROM notification_channel WHERE id = ?', [$id]) ?: throw new NotFoundException('Canal não encontrado.'));
        $p = new Payload($data);
        $name = $p->string('name', 100, required: true);
        $kind = $p->enum('kind', NotificationChannel::KINDS, 'webhook');
        $target = $p->string('target', 2000, required: null === $current);
        $chatId = 'telegram' === $kind ? $p->string('chat_id', 64, required: true) : null;
        $min = $p->enum('min_severity', ['info', 'warning', 'critical'], 'warning');
        $enabled = $p->bool('enabled', true);
        if (null !== $target) {
            if ('telegram' === $kind) {
                if (1 !== preg_match('/^\d{5,12}:[A-Za-z0-9_-]{30,64}$/', $target)) {
                    $p->error('target', 'Token do bot inválido (formato 123456789:AA…, obtido no @BotFather).');
                }
            } elseif (false === filter_var($target, \FILTER_VALIDATE_URL) || !\in_array(parse_url($target, \PHP_URL_SCHEME), ['https', 'http'], true)) {
                $p->error('target', 'URL do webhook inválida.');
            }
        }
        if (null !== $chatId && 1 !== preg_match('/^(-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/', $chatId)) {
            $p->error('chat_id', 'Chat ID numérico (ex.: -1001234567890) ou @canal.');
        }
        if (null !== $current && null === $target && $kind !== $current['kind']) {
            $p->error('target', 'Ao trocar o tipo, informe o destino novamente.');
        }
        $p->check();
        $params = ['name' => $name, 'kind' => $kind, 'chat' => $chatId, 'min' => $min, 'enabled' => $enabled ? 'true' : 'false',
            'target' => null === $target ? $current['target'] : $this->box->encrypt($target)];
        if (null === $id) {
            return (int) $this->db->fetchOne(
                'INSERT INTO notification_channel (name, kind, target, chat_id, min_severity, enabled, created_at, updated_at)
                 VALUES (:name, :kind, :target, :chat, :min, :enabled, now(), now()) RETURNING id',
                $params,
            );
        }
        $this->db->executeStatement(
            'UPDATE notification_channel SET name = :name, kind = :kind, target = :target, chat_id = :chat, min_severity = :min, enabled = :enabled,
                    last_status = CASE WHEN :changed THEN NULL ELSE last_status END, last_error = CASE WHEN :changed THEN NULL ELSE last_error END, updated_at = now()
             WHERE id = :id',
            $params + ['id' => $id, 'changed' => null !== $target ? 'true' : 'false'],
        );

        return $id;
    }

    public function delete(int $id): void
    {
        if (0 === $this->db->executeStatement('DELETE FROM notification_channel WHERE id = ?', [$id])) {
            throw new NotFoundException('Canal não encontrado.');
        }
    }

    public static function mask(string $kind, string $target): string
    {
        if ('telegram' === $kind) {
            return 'bot '.strtok($target, ':').':••••';
        }
        $u = parse_url($target);

        return ($u['scheme'] ?? 'https').'://'.($u['host'] ?? '?').(isset($u['path']) ? '/'.mb_substr(ltrim((string) $u['path'], '/'), 0, 12).'…' : '');
    }
}
