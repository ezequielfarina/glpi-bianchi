<?php

declare(strict_types=1);

namespace App\Inventory\Service;

use App\Inventory\Entity\Agent;
use App\Settings\Service\Settings;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Aciona o servidor HTTP embutido do GLPI Agent (porta 62354 por padrão):
 *   GET /now    → agente executa as tarefas imediatamente ("forçar inventário")
 *   GET /status → "status: waiting" | "status: running task Inventory"...
 *
 * Mesmo mecanismo do GLPI (src/Agent.php::requestAgent/getAgentURLs): tenta os
 * endereços conhecidos do agente — IP de origem do último contato e IPv4 das
 * interfaces físicas do ativo — até um responder.
 *
 * Requisitos no lado do agente (ver docs/inventory/forcar-inventario.md):
 *   - módulo httpd ativo (padrão) escutando na porta configurada;
 *   - IP deste servidor em `httpd-trust` (o agente confia por padrão no host do
 *     `server` configurado);
 *   - firewall liberando TCP servidor → agente na porta.
 */
final readonly class AgentWakeup
{
    public const string RESULT_OK = 'ok';
    public const string RESULT_FORBIDDEN = 'forbidden';
    public const string RESULT_UNREACHABLE = 'unreachable';
    public const string RESULT_NO_ADDRESS = 'no_address';

    public function __construct(
        private HttpClientInterface $httpClient,
        private Connection $db,
        private Settings $settings,
        #[Autowire(service: 'monolog.logger.inventory')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{result: string, message: string, address: ?string, tried: list<string>, answer: ?string}
     */
    public function now(Agent $agent): array
    {
        return $this->request($agent, 'now');
    }

    /**
     * @return array{result: string, message: string, address: ?string, tried: list<string>, answer: ?string}
     */
    public function status(Agent $agent): array
    {
        return $this->request($agent, 'status');
    }

    /**
     * @return array{result: string, message: string, address: ?string, tried: list<string>, answer: ?string}
     */
    private function request(Agent $agent, string $endpoint): array
    {
        $port = $this->settings->agentHttpPort();
        $addresses = $this->addresses($agent);
        if ([] === $addresses) {
            return ['result' => self::RESULT_NO_ADDRESS, 'message' => 'Nenhum endereço IP conhecido para este agente.', 'address' => null, 'tried' => [], 'answer' => null];
        }

        $tried = [];
        $forbidden = null;
        foreach ($addresses as $ip) {
            $host = str_contains($ip, ':') ? "[$ip]" : $ip;
            $url = \sprintf('http://%s:%d/%s', $host, $port, $endpoint);
            $tried[] = $url;
            try {
                $response = $this->httpClient->request('GET', $url, ['timeout' => 3, 'max_duration' => 5]);
                $code = $response->getStatusCode();
                $body = trim($response->getContent(false));

                if (200 === $code) {
                    $this->logger->info('Agent wake-up', ['device_id' => $agent->getDeviceId(), 'endpoint' => $endpoint, 'url' => $url]);

                    return [
                        'result' => self::RESULT_OK,
                        'message' => 'now' === $endpoint ? 'Inventário solicitado ao agente. O resultado chega em alguns segundos.' : 'Agente respondeu.',
                        'address' => $url,
                        'tried' => $tried,
                        'answer' => '' === $body ? null : (string) preg_replace('/^status:\s*/', '', mb_substr($body, 0, 500)),
                    ];
                }
                if (403 === $code) {
                    $forbidden = $url; // agente alcançável, mas não confia neste servidor
                }
            } catch (TransportExceptionInterface) {
                // endereço errado/inalcançável: tenta o próximo
            }
        }

        if (null !== $forbidden) {
            return [
                'result' => self::RESULT_FORBIDDEN,
                'message' => 'O agente respondeu, mas recusou o pedido (403). Inclua o IP deste servidor em "httpd-trust" na configuração do agente.',
                'address' => $forbidden,
                'tried' => $tried,
                'answer' => null,
            ];
        }

        return [
            'result' => self::RESULT_UNREACHABLE,
            'message' => \sprintf('Agente inalcançável na porta %d. Verifique se a máquina está ligada e se o firewall (Windows/FortiGate) libera TCP %d a partir deste servidor.', $port, $port),
            'address' => null,
            'tried' => $tried,
            'answer' => null,
        ];
    }

    /**
     * IP de origem do último contato + IPv4 das interfaces físicas do ativo vinculado.
     *
     * @return list<string>
     */
    private function addresses(Agent $agent): array
    {
        $ips = [];
        if (null !== $agent->getRemoteIp() && '' !== $agent->getRemoteIp()) {
            $ips[] = $agent->getRemoteIp();
        }
        $assetId = $agent->getAsset()?->getId();
        if (null !== $assetId) {
            /** @var list<string> $assetIps */
            $assetIps = $this->db->fetchFirstColumn(<<<'SQL'
                SELECT i.address FROM network_port p JOIN ip_address i ON i.network_port_id = p.id
                WHERE p.asset_id = :id AND i.version = 4 AND NOT p.virtual
                  AND i.address NOT LIKE '169.254.%' AND i.address NOT LIKE '127.%'
                ORDER BY p.id, i.id
                SQL, ['id' => $assetId]);
            array_push($ips, ...$assetIps);
        }

        return \array_slice(array_values(array_unique($ips)), 0, 4);
    }
}
