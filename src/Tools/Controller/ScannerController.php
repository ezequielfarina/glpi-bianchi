<?php

declare(strict_types=1);

namespace App\Tools\Controller;

use App\Identity\Security\CurrentActor;
use App\Tools\Scanner\NmapRunner;
use App\Tools\Scanner\ScanProfile;
use App\Tools\Scanner\ScanTarget;
use App\Tools\Scanner\SnmpArpCollector;
use App\Tools\Service\ScanService;
use App\Ui\Controller\UiJson;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/** Ferramentas → Scanner de rede. Acesso: Técnico ou Administrador (security.yaml). */
#[AsController]
#[Route('/api/ui/tools/scanner', name: 'tools_scanner_')]
final readonly class ScannerController
{
    use UiJson;

    public function __construct(
        private ScanService $scans,
        private NmapRunner $nmap,
        private SnmpArpCollector $snmp,
        private CurrentActor $actor,
        private Connection $db,
    ) {
    }

    /** Estado da ferramenta + redes sugeridas (interfaces do servidor e redes das filiais). */
    #[Route('', name: 'info', methods: ['GET'])]
    public function info(): JsonResponse
    {
        $suggestions = [];
        foreach ($this->localNetworks() as $cidr) {
            $suggestions[] = ['cidr' => $cidr, 'label' => 'Rede do servidor'];
        }
        foreach ($this->db->fetchAllAssociative("SELECT b.name, n.cidr FROM branch b CROSS JOIN LATERAL jsonb_array_elements_text(b.networks) n(cidr) WHERE b.active ORDER BY b.kind = 'headquarters' DESC, b.name") as $r) {
            $suggestions[] = ['cidr' => $r['cidr'], 'label' => $r['name']];
        }

        return $this->json([
            'nmap' => $this->nmap->version(),
            'snmp' => $this->snmp->available(),
            'profiles' => array_map(static fn (string $p): array => ['code' => $p, 'label' => ScanProfile::LABEL[$p], 'max_prefix' => ScanTarget::MAX_PREFIX[$p]], ScanProfile::ALL),
            'suggestions' => array_values(array_unique($suggestions, \SORT_REGULAR)),
        ]);
    }

    #[Route('/scans', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['items' => $this->scans->list()]);
    }

    #[Route('/scans', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->scans->create($this->body($request), $this->actor->name())], Response::HTTP_ACCEPTED);
    }

    #[Route('/scans/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        return $this->json($this->scans->detail($id));
    }

    #[Route('/scans/{id}/cancel', name: 'cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id): JsonResponse
    {
        $this->scans->cancel($id);

        return $this->json(['ok' => true]);
    }

    #[Route('/scans/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->scans->delete($id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/scans/{id}/export', name: 'export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function export(int $id): Response
    {
        return new Response($this->scans->csv($id), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="varredura-%d.csv"', $id),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return list<string> redes IPv4 privadas das interfaces do servidor (ex.: 192.168.110.0/24) */
    private function localNetworks(): array
    {
        $json = @shell_exec('ip -j -4 addr show 2>/dev/null');
        $ifaces = \is_string($json) ? json_decode($json, true) : null;
        $out = [];
        foreach (\is_array($ifaces) ? $ifaces : [] as $if) {
            foreach ($if['addr_info'] ?? [] as $a) {
                try {
                    $out[] = ScanTarget::parse($a['local'].'/'.$a['prefixlen'], ScanProfile::DISCOVERY)->cidr();
                } catch (\InvalidArgumentException) {
                    // loopback, pública ou grande demais: não sugere
                }
            }
        }

        return array_values(array_unique($out));
    }
}
