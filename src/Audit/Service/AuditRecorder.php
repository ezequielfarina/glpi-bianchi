<?php

declare(strict_types=1);

namespace App\Audit\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Grava a trilha de auditoria (ADR-0015). Nunca derruba a requisição: falha de gravação vai para o log.
 * O corpo da requisição é guardado sem segredos (senhas, tokens, chaves, community SNMP, destino de canal).
 */
final readonly class AuditRecorder
{
    private const int MAX_DETAILS = 16_384;
    private const string SECRET_KEYS = '/pass|secret|token|totp|otp|^code$|community|license_key|^key$|api_key|chat_id|webhook/i';

    public function __construct(
        private Connection $db,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Ação, descrição, módulo, tipo e parâmetro do id para a rota (catálogo ou genérico).
     *
     * @return array{0: string, 1: string, 2: string, 3: string|null, 4: string|null}
     */
    public static function describe(string $route, string $method): array
    {
        return AuditCatalog::ROUTES[$route] ?? [mb_substr($route, 0, 64), \sprintf('%s %s', $method, $route), 'other', null, null];
    }

    /** Texto legível do objeto da ação (lido ANTES de executar, para exclusões guardarem o que foi apagado). */
    public function subject(?string $type, ?string $id): ?string
    {
        if (null === $type || null === $id || '' === $id) {
            return null;
        }
        $isUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
        $isInt = (bool) preg_match('/^\d{1,10}$/', $id);
        $sql = match (true) {
            'asset' === $type && $isUuid => "SELECT concat_ws(' — ', inventory_number, name) FROM asset WHERE uuid = CAST(? AS uuid)",
            'branch' === $type && $isInt => "SELECT name || coalesce(' (' || code || ')', '') FROM branch WHERE id = ?",
            'supplier' === $type && $isInt => 'SELECT name FROM supplier WHERE id = ?',
            'contact' === $type && $isInt => "SELECT c.name || coalesce(' — ' || s.name, '') FROM contact c LEFT JOIN supplier s ON s.id = c.supplier_id WHERE c.id = ?",
            'contract' === $type && $isInt => "SELECT concat_ws(' — ', number, title) FROM contract WHERE id = ?",
            'license' === $type && $isInt => 'SELECT name FROM license WHERE id = ?',
            'license_assignment' === $type && $isInt => "SELECT l.name || coalesce(' → ' || a.name, '') FROM license_assignment la JOIN license l ON l.id = la.license_id LEFT JOIN asset a ON a.id = la.asset_id WHERE la.id = ?",
            'attachment' === $type && $isUuid => "SELECT filename || ' (' || owner_type || ' #' || owner_id || ')' FROM attachment WHERE uuid = CAST(? AS uuid)",
            'stock_item' === $type && $isInt => 'SELECT name FROM stock_item WHERE id = ?',
            'invoice' === $type && $isInt => "SELECT 'NF ' || n.number || CASE WHEN n.series <> '' THEN '/' || n.series ELSE '' END || ' — ' || s.name FROM invoice n JOIN supplier s ON s.id = n.supplier_id WHERE n.id = ?",
            'scan' === $type && $isInt => "SELECT target || ' (' || profile || ')' FROM network_scan WHERE id = ?",
            'schedule' === $type && $isInt => "SELECT name || ' — ' || target FROM scan_schedule WHERE id = ?",
            'channel' === $type && $isInt => "SELECT name || ' (' || kind || ')' FROM notification_channel WHERE id = ?",
            'user' === $type && $isInt => "SELECT username || ' — ' || display_name FROM app_account WHERE id = ?",
            'location' === $type && $isInt => "SELECT l.name || coalesce(' — ' || b.name, '') FROM location l LEFT JOIN branch b ON b.id = l.branch_id WHERE l.id = ?",
            'rack' === $type && $isInt => "SELECT r.name || ' — ' || b.name FROM dcim_rack r JOIN branch b ON b.id = r.branch_id WHERE r.id = ?",
            'device_role' === $type && $isInt => 'SELECT name FROM dcim_device_role WHERE id = ?',
            'platform' === $type && $isInt => 'SELECT name FROM dcim_platform WHERE id = ?',
            'device_type' === $type && $isInt => "SELECT m.name || ' ' || t.model FROM dcim_device_type t JOIN manufacturer m ON m.id = t.manufacturer_id WHERE t.id = ?",
            'port_template' === $type && $isInt => "SELECT t.model || ' — ' || p.name FROM dcim_port_template p JOIN dcim_device_type t ON t.id = p.device_type_id WHERE p.id = ?",
            'device' === $type && $isInt => "SELECT d.name || ' — ' || b.name FROM dcim_device d JOIN branch b ON b.id = d.branch_id WHERE d.id = ?",
            'port' === $type && $isInt => "SELECT d.name || ' ' || p.name FROM dcim_port p JOIN dcim_device d ON d.id = p.device_id WHERE p.id = ?",
            'cable' === $type && $isInt => "SELECT coalesce(label, 'Cabo #' || id) FROM dcim_cable WHERE id = ?",
            'prefix' === $type && $isInt => "SELECT prefix::text || coalesce(' — ' || description, '') FROM ipam_prefix WHERE id = ?",
            'ip_range' === $type && $isInt => "SELECT host(start_address) || ' – ' || host(end_address) FROM ipam_ip_range WHERE id = ?",
            'ip_address' === $type && $isInt => "SELECT host(address) || '/' || masklen(address) || coalesce(' — ' || dns_name, '') FROM ipam_ip_address WHERE id = ?",
            'vrf' === $type && $isInt => 'SELECT name FROM ipam_vrf WHERE id = ?',
            'vlan_group' === $type && $isInt => 'SELECT name FROM ipam_vlan_group WHERE id = ?',
            'vlan' === $type && $isInt => "SELECT vid || ' — ' || name FROM ipam_vlan WHERE id = ?",
            'circuit_type' === $type && $isInt => 'SELECT name FROM circuit_type WHERE id = ?',
            'circuit' === $type && $isInt => "SELECT c.cid || ' — ' || s.name FROM circuit c JOIN supplier s ON s.id = c.supplier_id WHERE c.id = ?",
            'circuit_termination' === $type && $isInt => "SELECT c.cid || ' lado ' || t.term_side FROM circuit_termination t JOIN circuit c ON c.id = t.circuit_id WHERE t.id = ?",
            'cluster' === $type && $isInt => "SELECT c.name || ' (' || t.name || ')' FROM virt_cluster c JOIN virt_cluster_type t ON t.id = c.type_id WHERE c.id = ?",
            'vm' === $type && $isInt => "SELECT v.name || ' — ' || c.name FROM virt_machine v JOIN virt_cluster c ON c.id = v.cluster_id WHERE v.id = ?",
            'vm_interface' === $type && $isInt => "SELECT v.name || ' ' || i.name FROM virt_interface i JOIN virt_machine v ON v.id = i.vm_id WHERE i.id = ?",
            'vm_disk' === $type && $isInt => "SELECT v.name || ' ' || k.name FROM virt_disk k JOIN virt_machine v ON v.id = k.vm_id WHERE k.id = ?",
            'unifi_controller' === $type && $isInt => 'SELECT name FROM unifi_controller WHERE id = ?',
            'unifi_site' === $type && $isInt => "SELECT coalesce(s.description, s.site_ref) || ' — ' || c.name FROM unifi_site s JOIN unifi_controller c ON c.id = s.controller_id WHERE s.id = ?",
            'unifi_wlan' === $type && $isInt => "SELECT w.name || ' @ ' || coalesce(s.description, s.site_ref) FROM unifi_wlan w JOIN unifi_site s ON s.id = w.site_id WHERE w.id = ?",
            'mac_identity' === $type && $isInt => "SELECT mac || coalesce(' — ' || person_name, '') FROM mac_identity WHERE id = ?",
            'agent' === $type => 'SELECT ? ',
            default => null,
        };
        if (null === $sql) {
            return null;
        }
        try {
            $v = $this->db->fetchOne($sql, [$id]);
        } catch (\Throwable) {
            return null;
        }

        return \is_string($v) && '' !== $v ? mb_substr($v, 0, 255) : null;
    }

    /**
     * @param array<string, mixed> $details
     */
    public function record(Request $request, string $route, int $status, ?int $accountId, string $actor, ?string $entityType, ?string $entityId,
        ?string $subject, array $details): void
    {
        [$action, , $module] = self::describe($route, $request->getMethod());
        try {
            $json = json_encode($details, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
            if (\strlen($json) > self::MAX_DETAILS) {
                $json = (string) json_encode(['truncated' => true, 'keys' => array_keys($details)]);
            }
            $this->db->executeStatement(
                'INSERT INTO audit_log (account_id, occurred_at, actor, ip, action, module, method, path, entity_type, entity_id, subject, status, details)
                 VALUES (?, now(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb))',
                [$accountId, mb_substr($actor, 0, 255), $request->getClientIp(), $action, $module, $request->getMethod(),
                    mb_substr($request->getPathInfo(), 0, 255), $entityType, null === $entityId ? null : mb_substr($entityId, 0, 64),
                    null === $subject ? null : mb_substr($subject, 0, 255), $status, $json],
            );
        } catch (\Throwable $e) {
            $this->logger->error('audit.write_failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Corpo da requisição sem segredos. Arquivos enviados viram {nome, tamanho, tipo}.
     *
     * @return array<string, mixed>
     */
    public static function requestData(Request $request, bool $maskTarget = false): array
    {
        $data = [];
        if (str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $decoded = json_decode($request->getContent(), true);
            $data = \is_array($decoded) ? $decoded : [];
        } else {
            $data = $request->request->all();
            foreach ($request->files->all() as $k => $f) {
                if ($f instanceof UploadedFile) {
                    $data[$k] = ['name' => $f->getClientOriginalName(), 'size' => $f->getSize(), 'type' => $f->getClientMimeType()];
                }
            }
        }

        /** @var array<string, mixed> */
        return self::scrub($data, $maskTarget);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function scrub(array $data, bool $maskTarget = false, int $depth = 0): array
    {
        if ($depth > 6) {
            return ['…' => 'aninhamento demais'];
        }
        foreach ($data as $k => $v) {
            if (\is_string($k) && (preg_match(self::SECRET_KEYS, $k) || ($maskTarget && 'target' === $k))) {
                $data[$k] = null === $v || '' === $v ? $v : '***';
            } elseif (\is_array($v)) {
                $data[$k] = self::scrub($v, $maskTarget, $depth + 1);
            } elseif (\is_string($v) && mb_strlen($v) > 2000) {
                $data[$k] = mb_substr($v, 0, 2000).'…';
            }
        }

        return $data;
    }
}
