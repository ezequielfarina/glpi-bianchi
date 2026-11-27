<?php

declare(strict_types=1);

namespace App\Tools\Controller;

use App\Tools\Net\Command;
use App\Tools\Net\DiagnosticsService;
use App\Tools\Net\LookupService;
use App\Tools\Scanner\MacVendors;
use App\Tools\Service\ConflictService;
use App\Ui\Controller\UiJson;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ferramentas → Diagnóstico e consultas (ping, traceroute/MTR, portas, DNS, ASN, RDAP, MAC)
 * e Conflitos de IP/MAC (ADR-0013). Tudo executado a partir do servidor; cada uso fica no log `tools`.
 */
#[AsController]
#[Route('/api/ui/tools', name: 'tools_net_')]
final readonly class NetController
{
    use UiJson;

    public function __construct(
        private DiagnosticsService $diag,
        private LookupService $lookup,
        private ConflictService $conflicts,
        private MacVendors $vendors,
        private LoggerInterface $logger,
    ) {
    }

    /** O que está disponível no servidor (para a tela avisar o que falta instalar). */
    #[Route('/net', name: 'info', methods: ['GET'])]
    public function info(): JsonResponse
    {
        return $this->json([
            'tools' => [
                'ping' => Command::exists('ping'), 'mtr' => Command::exists('mtr'), 'traceroute' => Command::exists('traceroute'),
                'dig' => Command::exists('dig'), 'arp_scan' => Command::exists('arp-scan'), 'mac_db' => $this->vendors->available(),
            ],
            'resolvers' => LookupService::systemResolvers(),
            'dns_types' => LookupService::DNS_TYPES,
            'port_names' => DiagnosticsService::PORT_NAMES,
        ]);
    }

    #[Route('/net/ping', name: 'ping', methods: ['POST'])]
    public function ping(Request $request): JsonResponse
    {
        $b = $this->body($request);
        $this->audit('ping', $b['host'] ?? null);

        return $this->json($this->diag->ping((string) ($b['host'] ?? ''), (int) ($b['count'] ?? 4)));
    }

    #[Route('/net/trace', name: 'trace', methods: ['POST'])]
    public function trace(Request $request): JsonResponse
    {
        $b = $this->body($request);
        $this->audit('trace', $b['host'] ?? null);

        return $this->json($this->diag->trace((string) ($b['host'] ?? ''), (int) ($b['cycles'] ?? 3)));
    }

    #[Route('/net/ports', name: 'ports', methods: ['POST'])]
    public function ports(Request $request): JsonResponse
    {
        $b = $this->body($request);
        $this->audit('ports', $b['host'] ?? null, ['ports' => $b['ports'] ?? null]);

        return $this->json($this->diag->ports((string) ($b['host'] ?? ''), (string) ($b['ports'] ?? ''), true === ($b['tls'] ?? false)));
    }

    #[Route('/net/dns', name: 'dns', methods: ['POST'])]
    public function dns(Request $request): JsonResponse
    {
        $b = $this->body($request);

        return $this->json($this->lookup->dns((string) ($b['name'] ?? ''), (string) ($b['type'] ?? 'A'), array_map('strval', (array) ($b['servers'] ?? []))));
    }

    #[Route('/net/asn', name: 'asn', methods: ['POST'])]
    public function asn(Request $request): JsonResponse
    {
        return $this->json($this->lookup->asn((string) ($this->body($request)['query'] ?? '')));
    }

    #[Route('/net/rdap', name: 'rdap', methods: ['POST'])]
    public function rdap(Request $request): JsonResponse
    {
        return $this->json($this->lookup->rdap((string) ($this->body($request)['query'] ?? '')));
    }

    #[Route('/net/mac', name: 'mac', methods: ['POST'])]
    public function mac(Request $request): JsonResponse
    {
        return $this->json(['items' => $this->lookup->macs((string) ($this->body($request)['macs'] ?? ''))]);
    }

    #[Route('/conflicts', name: 'conflicts', methods: ['GET'])]
    public function conflicts(Request $request): JsonResponse
    {
        return $this->json($this->conflicts->report($request->query->getInt('days', 7)));
    }

    /** @param array<string, mixed> $extra */
    private function audit(string $tool, mixed $host, array $extra = []): void
    {
        $this->logger->info('Network diagnostic', ['tool' => $tool, 'host' => \is_string($host) ? mb_substr($host, 0, 253) : null] + $extra);
    }
}
