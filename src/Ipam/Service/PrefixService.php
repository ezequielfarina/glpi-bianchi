<?php

declare(strict_types=1);

namespace App\Ipam\Service;

use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\Refs;
use App\Ui\Write\ValidationException;
use Doctrine\DBAL\Connection;

/**
 * Prefixos e faixas de IP (ADR-0016 §7). Porta de `ipam.Prefix` e `ipam.IPRange` (netbox/ipam/models/ip.py):
 *  - hierarquia por contenção (`<<`) na mesma VRF, sem parent_id (Prefix.get_parents/get_children);
 *  - utilização: contêiner conta os prefixos-filho; os demais contam IPs + faixas marcadas como usadas
 *    (Prefix.get_utilization);
 *  - prefixo duplicado recusado (ENFORCE_GLOBAL_UNIQUE / VRF.enforce_unique);
 *  - faixas não se sobrepõem na mesma VRF (IPRange.clean).
 */
final readonly class PrefixService
{
    public const array STATUS = ['container' => 'Contêiner', 'active' => 'Ativo', 'reserved' => 'Reservado', 'deprecated' => 'Obsoleto'];
    public const array RANGE_STATUS = ['active' => 'Ativa', 'reserved' => 'Reservada', 'deprecated' => 'Obsoleta'];

    /** Tamanho do bloco e utilização calculados no banco (ver get_utilization). */
    private const string COLUMNS = <<<'SQL'
        p.id, p.prefix::text AS prefix, family(p.prefix) AS family, masklen(p.prefix) AS len, p.status, p.role, p.is_pool, p.mark_utilized, p.description,
        p.branch_id, b.name AS branch, p.location_id, l.name AS location, p.vlan_id, vl.vid AS vlan_vid, vl.name AS vlan,
        (SELECT count(*) FROM ipam_prefix x WHERE p.prefix << x.prefix) AS depth,
        power(2::numeric, CASE family(p.prefix) WHEN 4 THEN 32 ELSE 128 END - masklen(p.prefix))::float8 AS size,
        CASE WHEN p.status = 'container' THEN
            (SELECT coalesce(sum(power(2::numeric, CASE family(c.prefix) WHEN 4 THEN 32 ELSE 128 END - masklen(c.prefix))), 0)::float8
               FROM ipam_prefix c WHERE c.prefix << p.prefix
                AND NOT EXISTS (SELECT 1 FROM ipam_prefix m WHERE m.prefix << p.prefix AND c.prefix << m.prefix))
        ELSE
            (SELECT count(*) FROM ipam_ip_address i WHERE inet(host(i.address)) <<= p.prefix
                AND NOT EXISTS (SELECT 1 FROM ipam_ip_range r WHERE r.mark_utilized
                                AND inet(host(i.address)) BETWEEN inet(host(r.start_address)) AND inet(host(r.end_address))))
          + (SELECT coalesce(sum((inet(host(r.end_address)) - inet(host(r.start_address))) + 1), 0) FROM ipam_ip_range r
              WHERE r.mark_utilized AND inet(host(r.start_address)) <<= p.prefix)
        END::float8 AS used,
        (SELECT count(*) FROM ipam_prefix c WHERE c.prefix << p.prefix) AS children
        SQL;

    private const string JOINS = 'FROM ipam_prefix p LEFT JOIN branch b ON b.id = p.branch_id
        LEFT JOIN location l ON l.id = p.location_id LEFT JOIN ipam_vlan vl ON vl.id = p.vlan_id';

    public function __construct(private Connection $db)
    {
    }

    /**
     * Lista em árvore (ordem do CIDR = pai antes dos filhos), com profundidade e utilização.
     *
     * @param array{q?: string|null, branch?: int|null, status?: string|null, family?: int|null, within?: string|null} $f
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $f = []): array
    {
        $within = null;
        if (null !== ($f['within'] ?? null)) {
            $w = IpNet::parse((string) $f['within']);
            $within = null === $w ? null : IpNet::cidr($w['bin'], $w['len']);
        }
        $q = $f['q'] ?? null;
        $qNet = null === $q ? null : IpNet::parse($q);
        $rows = $this->db->fetchAllAssociative('SELECT '.self::COLUMNS.' '.self::JOINS.<<<'SQL'
             WHERE TRUE
              AND (CAST(:branch AS int) IS NULL OR p.branch_id = :branch)
              AND (CAST(:status AS text) IS NULL OR p.status = :status)
              AND (CAST(:family AS int) IS NULL OR family(p.prefix) = :family)
              AND (CAST(:within AS cidr) IS NULL OR p.prefix << CAST(:within AS cidr))
              AND (CAST(:q AS text) IS NULL OR p.description ILIKE '%' || CAST(:q AS text) || '%' OR p.role ILIKE '%' || CAST(:q AS text) || '%'
                   OR p.prefix::text LIKE CAST(:q AS text) || '%' OR (CAST(:qnet AS inet) IS NOT NULL AND p.prefix >>= CAST(:qnet AS inet)))
            ORDER BY p.prefix
            LIMIT 5000
            SQL, ['branch' => $f['branch'] ?? null, 'status' => $f['status'] ?? null, 'family' => $f['family'] ?? null,
            'within' => $within, 'q' => $q, 'qnet' => null === $qNet ? null : IpNet::text($qNet['bin'])]);

        return array_map(fn (array $r): array => $this->decorate($r), $rows);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $p = $this->db->fetchAssociative('SELECT '.self::COLUMNS.', p.comments '.self::JOINS.' WHERE p.id = ?', [$id]) ?: throw new NotFoundException('Prefixo não encontrado.');
        $p = $this->decorate($p);
        $params = ['p' => $p['prefix']];
        $p['parents'] = $this->db->fetchAllAssociative(
            'SELECT id, prefix::text AS prefix, status, description FROM ipam_prefix WHERE CAST(:p AS cidr) << prefix ORDER BY prefix',
            $params,
        );
        $p['children'] = array_map(fn (array $r): array => $this->decorate($r), $this->db->fetchAllAssociative('SELECT '.self::COLUMNS.' '.self::JOINS.<<<'SQL'
             WHERE p.prefix << CAST(:p AS cidr)
              AND NOT EXISTS (SELECT 1 FROM ipam_prefix m WHERE m.prefix << CAST(:p AS cidr) AND p.prefix << m.prefix)
            ORDER BY p.prefix
            SQL, $params));
        $p['ranges'] = $this->ranges(['within' => (string) $p['prefix']]);
        $p['ips'] = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT i.id, host(i.address) AS host, host(i.address) || '/' || masklen(i.address) AS address, i.status, i.role, i.dns_name, i.description,
                   i.port_id, po.name AS port, d.id AS device_id, d.name AS device, (d.primary_ip_id = i.id) AS is_primary
            FROM ipam_ip_address i LEFT JOIN dcim_port po ON po.id = i.port_id LEFT JOIN dcim_device d ON d.id = po.device_id
            WHERE inet(host(i.address)) <<= CAST(:p AS cidr)
            ORDER BY i.address LIMIT 4096
            SQL, $params);
        $p['first_available_ip'] = $this->firstAvailableIp($p);
        $p['available_prefixes'] = $this->availablePrefixes($p, $p['children']);
        $p['slots'] = $this->slots($p);

        return $p;
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        $p = new Payload($data);
        $text = $p->string('prefix', 64, required: true);
        $net = null === $text ? null : IpNet::parse($text);
        if (null !== $text && (null === $net || !str_contains($text, '/'))) {
            $p->error('prefix', 'Prefixo inválido (ex.: 192.168.10.0/24 ou 2001:db8::/48).');
        }
        $branchId = $p->int('branch_id', 1);
        $locationId = $p->int('location_id', 1);
        $vlanId = $p->int('vlan_id', 1);
        $this->checkRefs($p, $branchId, $locationId, $vlanId);
        $cidr = null === $net ? null : IpNet::cidr($net['bin'], $net['len']);
        if (null !== $cidr && false !== $this->db->fetchOne(
            'SELECT 1 FROM ipam_prefix WHERE prefix = CAST(? AS cidr) AND id <> ?',
            [$cidr, $id ?? 0],
        )) {
            $p->error('prefix', 'Esse prefixo já existe nesta VRF.');
        }
        $p->check();
        $row = ['prefix' => $cidr, 'branch_id' => $branchId, 'location_id' => $locationId, 'vlan_id' => $vlanId,
            'status' => $p->enum('status', array_keys(self::STATUS), 'active'), 'role' => $p->string('role', 64), 'is_pool' => $p->bool('is_pool') ? 'true' : 'false',
            'mark_utilized' => $p->bool('mark_utilized') ? 'true' : 'false', 'description' => $p->string('description', 255), 'comments' => $p->string('comments', 10000)];
        $p->check();
        if (null === $id) {
            return (int) $this->db->fetchOne(
                'INSERT INTO ipam_prefix (prefix, branch_id, location_id, vlan_id, status, role, is_pool, mark_utilized, description, comments, created_at, updated_at)
                 VALUES (CAST(? AS cidr), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [...array_values($row), Refs::now(), Refs::now()],
            );
        }
        if (0 === $this->db->update('ipam_prefix', $row + ['updated_at' => Refs::now()], ['id' => $id])) {
            throw new NotFoundException('Prefixo não encontrado.');
        }

        return $id;
    }

    public function delete(int $id): void
    {
        if (0 === $this->db->executeStatement('DELETE FROM ipam_prefix WHERE id = ?', [$id])) {
            throw new NotFoundException('Prefixo não encontrado.');
        }
    }

    /** Próximo IP livre do prefixo, com a máscara do prefixo (ex.: 192.168.10.51/24). */
    public function nextIp(int $id): ?string
    {
        $p = $this->db->fetchAssociative('SELECT id, prefix::text AS prefix, is_pool, status FROM ipam_prefix WHERE id = ?', [$id]) ?: throw new NotFoundException('Prefixo não encontrado.');

        return $this->firstAvailableIp($p);
    }

    // ------------------------------------------------------------------ faixas

    /**
     * @param array{q?: string|null, within?: string|null, status?: string|null} $f
     *
     * @return list<array<string, mixed>>
     */
    public function ranges(array $f = []): array
    {
        return array_map(static function (array $r): array {
            $r['status_label'] = self::RANGE_STATUS[(string) $r['status']] ?? $r['status'];
            $r['size'] = (float) $r['size'];

            return $r;
        }, $this->db->fetchAllAssociative(<<<'SQL'
            SELECT r.id, host(r.start_address) AS start_address, host(r.end_address) AS end_address, masklen(r.start_address) AS len,
                   (inet(host(r.end_address)) - inet(host(r.start_address)) + 1)::float8 AS size, r.status, r.role, r.mark_utilized, r.description,
                   (SELECT count(*) FROM ipam_ip_address i WHERE inet(host(i.address)) BETWEEN inet(host(r.start_address)) AND inet(host(r.end_address))) AS used
            FROM ipam_ip_range r
            WHERE TRUE
              AND (CAST(:within AS cidr) IS NULL OR inet(host(r.start_address)) <<= CAST(:within AS cidr))
              AND (CAST(:status AS text) IS NULL OR r.status = :status)
              AND (CAST(:q AS text) IS NULL OR r.description ILIKE '%' || CAST(:q AS text) || '%' OR r.role ILIKE '%' || CAST(:q AS text) || '%'
                   OR host(r.start_address) LIKE CAST(:q AS text) || '%')
            ORDER BY r.start_address
            SQL, ['within' => $f['within'] ?? null, 'status' => $f['status'] ?? null, 'q' => $f['q'] ?? null]));
    }

    /** @param array<string, mixed> $data */
    public function saveRange(?int $id, array $data): int
    {
        $p = new Payload($data);
        $startText = $p->string('start_address', 64, required: true);
        $endText = $p->string('end_address', 64, required: true);
        $start = null === $startText ? null : IpNet::parse($startText);
        $end = null === $endText ? null : IpNet::parse($endText);
        if (null !== $startText && null === $start) {
            $p->error('start_address', 'Endereço inválido.');
        }
        if (null !== $endText && null === $end) {
            $p->error('end_address', 'Endereço inválido.');
        }
        $p->check();
        \assert(null !== $start && null !== $end);
        if ($start['family'] !== $end['family']) {
            throw new ValidationException(['end_address' => 'Início e fim precisam ser da mesma família (IPv4/IPv6).']);
        }
        if (strcmp($start['bin'], $end['bin']) > 0) {
            throw new ValidationException(['end_address' => 'O fim precisa ser maior ou igual ao início.']);
        }
        // máscara: a informada no início, senão a do menor prefixo que contém a faixa (como no formulário do NetBox)
        $len = str_contains((string) $startText, '/') ? $start['len'] : $this->containingLen(IpNet::text($start['bin'])) ?? $start['bits'];
        $s = IpNet::text($start['bin']).'/'.$len;
        $e = IpNet::text($end['bin']).'/'.$len;
        if (IpNet::network($start['bin'], $len) !== IpNet::network($end['bin'], $len)) {
            throw new ValidationException(['end_address' => \sprintf('Início e fim precisam estar na mesma rede /%d.', $len)]);
        }
        $overlap = $this->db->fetchOne(<<<'SQL'
            SELECT host(start_address) || ' – ' || host(end_address) FROM ipam_ip_range
            WHERE id <> ?
              AND inet(host(start_address)) <= inet(host(CAST(? AS inet))) AND inet(host(end_address)) >= inet(host(CAST(? AS inet)))
            LIMIT 1
            SQL, [$id ?? 0, $e, $s]);
        if (false !== $overlap) {
            throw new ValidationException(['start_address' => 'Sobrepõe a faixa '.$overlap.'.']);
        }
        $row = ['start_address' => $s, 'end_address' => $e, 'status' => $p->enum('status', array_keys(self::RANGE_STATUS), 'active'),
            'role' => $p->string('role', 64), 'mark_utilized' => $p->bool('mark_utilized') ? 'true' : 'false', 'description' => $p->string('description', 255)];
        $p->check();
        if (null === $id) {
            return (int) $this->db->fetchOne(
                'INSERT INTO ipam_ip_range (start_address, end_address, status, role, mark_utilized, description, created_at, updated_at)
                 VALUES (CAST(? AS inet), CAST(? AS inet), ?, ?, ?, ?, ?, ?) RETURNING id',
                [...array_values($row), Refs::now(), Refs::now()],
            );
        }
        if (0 === $this->db->update('ipam_ip_range', $row + ['updated_at' => Refs::now()], ['id' => $id])) {
            throw new NotFoundException('Faixa não encontrada.');
        }

        return $id;
    }

    public function deleteRange(int $id): void
    {
        if (0 === $this->db->executeStatement('DELETE FROM ipam_ip_range WHERE id = ?', [$id])) {
            throw new NotFoundException('Faixa não encontrada.');
        }
    }

    /** Próximo IP livre dentro da faixa. */
    public function nextIpInRange(int $id): ?string
    {
        $r = $this->db->fetchAssociative('SELECT host(start_address) AS s, host(end_address) AS e, masklen(start_address) AS len FROM ipam_ip_range WHERE id = ?', [$id])
            ?: throw new NotFoundException('Faixa não encontrada.');
        $first = (string) inet_pton((string) $r['s']);
        $last = (string) inet_pton((string) $r['e']);
        $used = array_map(static fn (string $h): array => [(string) inet_pton($h), (string) inet_pton($h)], array_map('strval', $this->db->fetchFirstColumn(
            'SELECT host(address) FROM ipam_ip_address WHERE inet(host(address)) BETWEEN CAST(? AS inet) AND CAST(? AS inet)',
            [$r['s'], $r['e']],
        )));
        $free = IpNet::firstFree($first, $last, $used);

        return null === $free ? null : IpNet::text($free).'/'.$r['len'];
    }

    // ------------------------------------------------------------------ internos

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function decorate(array $r): array
    {
        $size = (float) $r['size'];
        $len = (int) $r['len'];
        $bits = 4 === (int) $r['family'] ? 32 : 128;
        // IPv4 comum: rede e broadcast não contam como utilizáveis (como no NetBox)
        $usable = 'container' !== $r['status'] && 4 === (int) $r['family'] && $len < 31 && !(bool) $r['is_pool'] ? $size - 2 : $size;
        $used = (bool) $r['mark_utilized'] ? $usable : (float) $r['used'];
        $r['usable'] = $usable;
        $r['size'] = $size;
        $r['used'] = (float) $r['used'];
        $r['utilization'] = $usable > 0 ? min(100.0, round(100 * $used / $usable, 1)) : 0.0;
        $r['status_label'] = self::STATUS[(string) $r['status']] ?? $r['status'];
        $r['bits'] = $bits;

        return $r;
    }

    /** @param array<string, mixed> $p */
    private function firstAvailableIp(array $p): ?string
    {
        if ('container' === ($p['status'] ?? null)) {
            return null;
        }
        $net = IpNet::parse((string) $p['prefix']);
        if (null === $net) {
            return null;
        }
        [$first, $last] = IpNet::usable($net['bin'], $net['len'], (bool) $p['is_pool']);
        $used = [];
        foreach ($this->db->fetchFirstColumn(
            'SELECT host(address) FROM ipam_ip_address WHERE inet(host(address)) <<= CAST(? AS cidr)',
            [$p['prefix']],
        ) as $h) {
            $b = (string) inet_pton((string) $h);
            $used[] = [$b, $b];
        }
        // faixas (ex.: pool DHCP) nunca entram como "próximo IP livre" para endereço estático
        foreach ($this->db->fetchAllAssociative(
            'SELECT host(start_address) AS s, host(end_address) AS e FROM ipam_ip_range WHERE inet(host(start_address)) <<= CAST(? AS cidr)',
            [$p['prefix']],
        ) as $r) {
            $used[] = [(string) inet_pton((string) $r['s']), (string) inet_pton((string) $r['e'])];
        }
        $free = IpNet::firstFree($first, $last, $used);

        return null === $free ? null : IpNet::text($free).'/'.$net['len'];
    }

    /**
     * @param array<string, mixed>       $p
     * @param list<array<string, mixed>> $children
     *
     * @return list<string>
     */
    private function availablePrefixes(array $p, array $children): array
    {
        if (4 !== (int) $p['family'] || ('container' !== $p['status'] && [] === $children)) {
            return [];
        }
        $net = IpNet::parse((string) $p['prefix']);
        if (null === $net) {
            return [];
        }
        $start = IpNet::v4int($net['bin']);
        $end = IpNet::v4int(IpNet::last($net['bin'], $net['len']));
        $used = [];
        foreach ($children as $c) {
            $cn = IpNet::parse((string) $c['prefix']);
            if (null !== $cn) {
                $used[] = [IpNet::v4int($cn['bin']), IpNet::v4int(IpNet::last($cn['bin'], $cn['len']))];
            }
        }

        return \array_slice(IpNet::freeCidrs4($start, $end, $used), 0, 256);
    }

    /**
     * Mapa do prefixo IPv4 pequeno (até /22): IPs, faixas e blocos livres em ordem, como a aba "IP Addresses" do NetBox.
     *
     * @param array<string, mixed> $p
     *
     * @return list<array<string, mixed>>|null
     */
    private function slots(array $p): ?array
    {
        if (4 !== (int) $p['family'] || (int) $p['len'] < 22 || 'container' === $p['status']) {
            return null;
        }
        $net = IpNet::parse((string) $p['prefix']);
        if (null === $net) {
            return null;
        }
        [$first, $last] = IpNet::usable($net['bin'], $net['len'], (bool) $p['is_pool']);
        $a = IpNet::v4int($first);
        $z = IpNet::v4int($last);
        $ips = [];
        /** @var list<array<string, mixed>> $list */
        $list = $p['ips'];
        foreach ($list as $ip) {
            $ips[ip2long((string) $ip['host'])][] = $ip;
        }
        $ranges = [];
        /** @var list<array<string, mixed>> $rangeList */
        $rangeList = $p['ranges'];
        foreach ($rangeList as $r) {
            $ranges[] = [ip2long((string) $r['start_address']), ip2long((string) $r['end_address']), $r];
        }
        $out = [];
        $free = null;
        $flush = static function () use (&$free, &$out): void {
            if (null !== $free) {
                $out[] = ['t' => 'free', 'first' => long2ip($free[0]), 'last' => long2ip($free[1]), 'count' => $free[1] - $free[0] + 1];
                $free = null;
            }
        };
        for ($i = $a; $i <= $z; ++$i) {
            if (isset($ips[$i])) {
                $flush();
                foreach ($ips[$i] as $ip) {
                    $out[] = ['t' => 'ip'] + $ip;
                }
                continue;
            }
            foreach ($ranges as [$rs, $re, $r]) {
                if ($i === $rs) {
                    $flush();
                    $out[] = ['t' => 'range'] + $r;
                }
                if ($i >= $rs && $i <= $re) {
                    continue 2;
                }
            }
            $free = null === $free ? [$i, $i] : [$free[0], $i];
        }
        $flush();

        return $out;
    }

    private function containingLen(string $host): ?int
    {
        $len = $this->db->fetchOne(
            'SELECT masklen(prefix) FROM ipam_prefix WHERE prefix >>= CAST(? AS inet) ORDER BY masklen(prefix) DESC LIMIT 1',
            [$host],
        );

        return false === $len ? null : (int) $len;
    }

    private function checkRefs(Payload $p, ?int $branchId, ?int $locationId, ?int $vlanId): void
    {
        if (null !== $branchId && false === $this->db->fetchOne('SELECT 1 FROM branch WHERE id = ?', [$branchId])) {
            $p->error('branch_id', 'Filial não encontrada.');
        }
        if (null !== $locationId && false === $this->db->fetchOne('SELECT 1 FROM location WHERE id = ? AND branch_id IS NOT DISTINCT FROM ?', [$locationId, $branchId])) {
            $p->error('location_id', 'O local precisa ser da mesma filial.');
        }
        if (null !== $vlanId && false === $this->db->fetchOne('SELECT 1 FROM ipam_vlan WHERE id = ?', [$vlanId])) {
            $p->error('vlan_id', 'VLAN não encontrada.');
        }
    }
}
