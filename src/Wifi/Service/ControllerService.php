<?php

declare(strict_types=1);

namespace App\Wifi\Service;

use App\Shared\Security\SecretBox;
use App\Ui\Write\NotFoundException;
use App\Ui\Write\Payload;
use App\Ui\Write\ValidationException;
use App\Wifi\Unifi\TlsInspector;
use App\Wifi\Unifi\UnifiClientFactory;
use App\Wifi\Unifi\UnifiConnection;
use App\Wifi\Unifi\UnifiException;
use Doctrine\DBAL\Connection;

/**
 * Cadastro dos controllers UniFi (ADR-0020). A senha/API key é cifrada com SecretBox (contexto
 * `unifi.secret:<id>`) e nunca volta para a interface — só `has_secret`.
 */
final readonly class ControllerService
{
    public const array AUTH = ['local' => 'Admin local (usuário e senha)', 'api_key' => 'API key (UniFi OS / UniFi OS Server)', 'cloud' => 'Nuvem (Site Manager — experimental)'];

    public function __construct(
        private Connection $db,
        private SecretBox $box,
        private UnifiClientFactory $factory,
        private TlsInspector $tls,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map(self::view(...), $this->db->fetchAllAssociative(<<<'SQL'
            SELECT c.*, (c.secret IS NOT NULL) AS has_secret,
                   (SELECT count(*) FROM unifi_site s WHERE s.controller_id = c.id AND s.present) AS sites,
                   (SELECT count(*) FROM unifi_wlan w JOIN unifi_site s ON s.id = w.site_id WHERE s.controller_id = c.id AND w.present) AS wlans
            FROM unifi_controller c ORDER BY c.name
            SQL));
    }

    /** @return array<string, mixed> linha completa (com segredo cifrado) para abrir conexão */
    public function row(int $id): array
    {
        $r = $this->db->fetchAssociative('SELECT * FROM unifi_controller WHERE id = ?', [$id]);

        return false === $r ? throw new NotFoundException('Controller UniFi não encontrado.') : $r;
    }

    public function connect(int $id): UnifiConnection
    {
        return $this->factory->connect($this->row($id));
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data): int
    {
        $current = null === $id ? null : $this->row($id);
        $p = new Payload($data);
        $name = $p->string('name', 100, required: true);
        $mode = $p->enum('auth_mode', array_keys(self::AUTH), 'local');
        $baseUrl = rtrim((string) $p->string('base_url', 255, required: 'cloud' !== $mode), '/');
        $username = $p->string('username', 100);
        $secret = $p->string('secret', 500);
        $hostId = $p->string('cloud_host_id', 100);
        $verify = $p->bool('verify_tls', true);
        $pin = $p->string('tls_pin', 64);

        if ('cloud' === $mode) {
            $baseUrl = 'https://api.ui.com';
            if (null === $hostId || 1 !== preg_match('/^[A-Za-z0-9:_\-]{6,100}$/', $hostId)) {
                $p->error('cloud_host_id', 'Informe o ID do console (aparece na URL do unifi.ui.com, antes de /network).');
            }
        } elseif (1 !== preg_match('#^https://[A-Za-z0-9.\-\[\]:]+(:\d{1,5})?$#', $baseUrl)) {
            $p->error('base_url', 'Use https://IP-ou-nome:porta (ex.: https://192.168.10.5:8443), sem caminho.');
        }
        if ('local' === $mode && null === $username) {
            $p->error('username', 'Informe o usuário do admin local do UniFi.');
        }
        if (null === $secret && null === ($current['secret'] ?? null)) {
            $p->error('secret', 'local' === $mode ? 'Informe a senha.' : 'Informe a API key.');
        }
        if (null !== $pin && 1 !== preg_match('#^[A-Za-z0-9+/]{43}=$#', $pin)) {
            $p->error('tls_pin', 'Pin inválido (base64 do SHA-256 da chave pública). Use "Ler certificado".');
        }
        if ('cloud' !== $mode && !$verify && null === $pin) {
            $p->error('tls_pin', 'Sem validação pela CA é obrigatório fixar o certificado do controller.');
        }
        $dup = $this->db->fetchOne('SELECT id FROM unifi_controller WHERE lower(name) = lower(?) AND id <> ?', [$name, $id ?? 0]);
        if (false !== $dup) {
            $p->error('name', 'Já existe um controller com esse nome.');
        }
        $p->check();

        $row = [
            'name' => $name, 'base_url' => $baseUrl, 'auth_mode' => $mode, 'username' => 'local' === $mode ? $username : null,
            'cloud_host_id' => 'cloud' === $mode ? $hostId : null, 'verify_tls' => $verify, 'tls_pin' => $verify ? null : $pin,
            'write_client_alias' => $p->bool('write_client_alias', true), 'enabled' => $p->bool('enabled', true),
        ];
        $types = ['verify_tls' => 'boolean', 'write_client_alias' => 'boolean', 'enabled' => 'boolean'];
        $this->db->transactional(function () use (&$id, $row, $types, $secret, $current): void {
            if (null === $id) {
                $this->db->insert('unifi_controller', $row + ['created_at' => date('c'), 'updated_at' => date('c')], $types);
                $id = (int) $this->db->lastInsertId();
            } else {
                // troca de modo de autenticação sem nova credencial: a antiga não serve mais
                $keep = null === $secret && ($current['auth_mode'] ?? null) === $row['auth_mode'];
                $this->db->update('unifi_controller', $row + ['updated_at' => date('c'), 'platform' => null] + ($keep ? [] : ['secret' => null]), ['id' => $id], $types);
            }
            if (null !== $secret) {
                $this->db->executeStatement('UPDATE unifi_controller SET secret = ? WHERE id = ?', [$this->box->encrypt($secret, UnifiClientFactory::context($id)), $id]);
            }
        });

        return (int) $id;
    }

    public function delete(int $id): void
    {
        $this->row($id);
        $this->db->delete('unifi_controller', ['id' => $id]);
    }

    /** @return array<string, mixed> certificado apresentado (para o admin conferir e fixar) */
    public function inspectTls(string $baseUrl): array
    {
        return $this->tls->inspect($baseUrl);
    }

    /**
     * Troca a senha/API key: testa a credencial nova no UniFi ANTES de gravar — digitação errada não derruba a integração.
     *
     * @param array<string, mixed> $data {secret, username?}
     *
     * @return array{ok: bool, platform: ?string, version: ?string, sites: int, error: ?string}
     */
    public function updateCredential(int $id, array $data): array
    {
        $row = $this->row($id);
        $p = new Payload($data);
        $secret = $p->string('secret', 500, required: true);
        $username = 'local' === $row['auth_mode'] ? ($p->string('username', 100) ?? (string) $row['username']) : null;
        $p->check();

        $conn = $this->factory->fromConfig([
            'base_url' => (string) $row['base_url'], 'auth_mode' => (string) $row['auth_mode'], 'username' => $username, 'secret' => $secret,
            'cloud_host_id' => null === $row['cloud_host_id'] ? null : (string) $row['cloud_host_id'], 'verify_tls' => (bool) $row['verify_tls'],
            'tls_pin' => null === $row['tls_pin'] ? null : (string) $row['tls_pin'],
        ]);
        try {
            $sites = $conn->sites();
            $version = null === ($sites[0] ?? null) ? null : $conn->version($sites[0]['ref']);
        } catch (UnifiException $e) {
            throw new ValidationException(['secret' => 'O UniFi não aceitou a credencial nova — nada foi alterado. '.$e->getMessage()]);
        } finally {
            $conn->logout();
        }
        $this->db->executeStatement(
            'UPDATE unifi_controller SET secret = ?, username = ?, platform = ?, version = coalesce(?, version), last_error = NULL, updated_at = now() WHERE id = ?',
            [$this->box->encrypt($secret, UnifiClientFactory::context($id)), $username, $conn->platform(), $version, $id]
        );

        return ['ok' => true, 'platform' => $conn->platform(), 'version' => $version, 'sites' => \count($sites), 'error' => null];
    }

    /** @return array{ok: bool, platform: ?string, version: ?string, sites: int, error: ?string} */
    public function test(int $id): array
    {
        $c = null;
        try {
            $c = $this->connect($id);
            $sites = $c->sites();
            $version = null === ($sites[0] ?? null) ? null : $c->version($sites[0]['ref']);
            $this->db->executeStatement('UPDATE unifi_controller SET platform = ?, version = ?, last_error = NULL, updated_at = now() WHERE id = ?', [$c->platform(), $version, $id]);

            return ['ok' => true, 'platform' => $c->platform(), 'version' => $version, 'sites' => \count($sites), 'error' => null];
        } catch (UnifiException $e) {
            $this->db->executeStatement('UPDATE unifi_controller SET last_error = ?, updated_at = now() WHERE id = ?', [$e->getMessage(), $id]);

            return ['ok' => false, 'platform' => null, 'version' => null, 'sites' => 0, 'error' => $e->getMessage()];
        } finally {
            $c?->logout();
        }
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private static function view(array $r): array
    {
        unset($r['secret']);
        foreach (['id', 'sites', 'wlans'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['auth_label'] = self::AUTH[$r['auth_mode']] ?? $r['auth_mode'];

        return $r;
    }
}
