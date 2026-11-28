<?php

declare(strict_types=1);

namespace App\Tools\Alert;

use App\Shared\Security\SecretBox;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envia alertas novos aos canais configurados (ADR-0013). Um envio por varredura e por canal
 * (resumo com até 15 linhas), filtrado pela severidade mínima do canal. Falha num canal não
 * impede os outros e fica registrada (`last_status`/`last_error`) para aparecer no painel.
 */
final readonly class Notifier
{
    private const array RANK = ['info' => 0, 'warning' => 1, 'critical' => 2];
    private const array ICON = ['critical' => '🔴', 'warning' => '🟠', 'info' => '🔵'];
    private const int MAX_LINES = 15;

    public function __construct(
        private Connection $db,
        private SecretBox $box,
        private HttpClientInterface $http,
        private LoggerInterface $logger,
        private string $publicUrl,
    ) {
    }

    /** @param list<int> $alertIds */
    public function notify(array $alertIds, string $context): void
    {
        if ([] === $alertIds) {
            return;
        }
        $alerts = $this->db->fetchAllAssociative(
            "SELECT id, kind, severity, title, ip, mac, detail, created_at FROM network_alert WHERE id IN (:ids)
             ORDER BY CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END, id",
            ['ids' => $alertIds], ['ids' => ArrayParameterType::INTEGER],
        );
        $channels = $this->db->fetchAllAssociative('SELECT * FROM notification_channel WHERE enabled ORDER BY id');
        foreach ($channels as $ch) {
            $min = self::RANK[$ch['min_severity']] ?? 1;
            $mine = array_values(array_filter($alerts, static fn (array $a): bool => (self::RANK[$a['severity']] ?? 0) >= $min));
            if ([] === $mine) {
                continue;
            }
            $this->deliver($ch, $context, $mine);
        }
        $this->db->executeStatement('UPDATE network_alert SET notified_at = now() WHERE id IN (:ids)', ['ids' => $alertIds], ['ids' => ArrayParameterType::INTEGER]);
    }

    /**
     * Mensagem de teste para um canal.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function test(int $channelId): array
    {
        $ch = $this->db->fetchAssociative('SELECT * FROM notification_channel WHERE id = ?', [$channelId]);
        if (false === $ch) {
            return ['ok' => false, 'error' => 'Canal não encontrado.'];
        }
        $ok = $this->deliver($ch, 'Teste de notificação', [[
            'id' => 0, 'kind' => 'baseline', 'severity' => 'info', 'title' => 'Se você recebeu esta mensagem, o canal "'.$ch['name'].'" está funcionando.',
            'ip' => null, 'mac' => null, 'detail' => '{}', 'created_at' => date(\DATE_ATOM),
        ]]);

        return ['ok' => $ok, 'error' => $ok ? null : (string) $this->db->fetchOne('SELECT last_error FROM notification_channel WHERE id = ?', [$channelId])];
    }

    /**
     * @param array<string, mixed>       $ch
     * @param list<array<string, mixed>> $alerts
     */
    private function deliver(array $ch, string $context, array $alerts): bool
    {
        $error = null;
        try {
            $target = $this->box->decrypt((string) $ch['target'], 'channel.target');
            [$url, $payload] = self::request((string) $ch['kind'], $target, $ch['chat_id'], $context, $alerts, $this->link());
            $resp = $this->http->request('POST', $url, ['json' => $payload, 'timeout' => 8, 'max_duration' => 15, 'max_redirects' => 0]);
            $code = $resp->getStatusCode();
            if ($code < 200 || $code >= 300) {
                $body = mb_substr($resp->getContent(false), 0, 300);
                $error = "HTTP {$code}".('' !== $body ? ': '.$body : '');
            }
        } catch (\Throwable $e) {
            // nunca registra a URL/token: a mensagem do HttpClient pode conter a URL
            $error = mb_substr(str_replace($target ?? "\0", '***', $e->getMessage()), 0, 300);
        }
        $this->db->executeStatement(
            'UPDATE notification_channel SET last_status = ?, last_error = ?, last_sent_at = now() WHERE id = ?',
            [null === $error ? 'ok' : 'error', $error, (int) $ch['id']],
        );
        if (null !== $error) {
            $this->logger->warning('Notification channel failed', ['channel' => $ch['name'], 'kind' => $ch['kind'], 'error' => $error]);
        }

        return null === $error;
    }

    /**
     * Monta URL + corpo por tipo de canal (público para teste unitário).
     *
     * @param list<array<string, mixed>> $alerts
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function request(string $kind, string $target, ?string $chatId, string $context, array $alerts, string $link): array
    {
        $n = \count($alerts);
        $title = \sprintf('GLPI Bianchi — %s: %d alerta%s', $context, $n, 1 === $n ? '' : 's');
        $lines = array_map(static fn (array $a): string => (self::ICON[$a['severity']] ?? '•').' '.$a['title'], \array_slice($alerts, 0, self::MAX_LINES));
        if ($n > self::MAX_LINES) {
            $lines[] = '… e mais '.($n - self::MAX_LINES);
        }

        return match ($kind) {
            'slack' => [$target, ['text' => '*'.$title."*\n".implode("\n", $lines).("" !== $link ? "\n<{$link}|Abrir no painel>" : '')]],
            'teams' => [$target, ['type' => 'message', 'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4',
                    'body' => array_merge(
                        [['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true]],
                        array_map(static fn (string $l): array => ['type' => 'TextBlock', 'text' => $l, 'wrap' => true, 'spacing' => 'Small'], $lines),
                    ),
                    'actions' => '' === $link ? [] : [['type' => 'Action.OpenUrl', 'title' => 'Abrir no painel', 'url' => $link]],
                ],
            ]]]],
            'telegram' => ['https://api.telegram.org/bot'.$target.'/sendMessage', [
                'chat_id' => $chatId,
                'text' => '<b>'.htmlspecialchars($title, \ENT_NOQUOTES).'</b>'."\n".implode("\n", array_map(static fn (string $l): string => htmlspecialchars($l, \ENT_NOQUOTES), $lines))
                    .('' !== $link ? "\n<a href=\"".htmlspecialchars($link).'">Abrir no painel</a>' : ''),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]],
            default => [$target, [
                'source' => 'glpi-bianchi',
                'event' => 'network_alerts',
                'title' => $title,
                'context' => $context,
                'url' => '' === $link ? null : $link,
                'alerts' => array_map(static fn (array $a): array => [
                    'id' => (int) $a['id'], 'kind' => $a['kind'], 'severity' => $a['severity'], 'title' => $a['title'],
                    'ip' => $a['ip'], 'mac' => $a['mac'], 'detail' => json_decode((string) $a['detail'], true) ?: (object) [], 'created_at' => $a['created_at'],
                ], $alerts),
            ]],
        };
    }

    private function link(): string
    {
        $base = rtrim($this->publicUrl, '/');

        return '' === $base || str_contains($base, '//localhost') ? '' : $base.'/app/tools/monitor';
    }
}
