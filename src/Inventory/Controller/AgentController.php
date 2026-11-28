<?php

declare(strict_types=1);

namespace App\Inventory\Controller;

use App\Inventory\Entity\Agent;
use App\Inventory\Protocol\AgentRequest;
use App\Inventory\Protocol\AgentRequestDecoder;
use App\Inventory\Protocol\AgentResponse;
use App\Inventory\Protocol\Compression;
use App\Inventory\Protocol\PayloadFormat;
use App\Inventory\Protocol\ProtocolException;
use App\Inventory\Security\AgentAccessGuard;
use App\Inventory\Service\AgentRegistry;
use App\Inventory\Service\InventoryReceiver;
use App\Settings\Service\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint compatível com o GLPI Agent / FusionInventory Agent.
 *
 * Rotas (todas aceitas pelo agente, conforme o `server` configurado nele):
 *   POST /                     → `server = https://host/`   (padrão recomendado)
 *   POST /front/inventory.php  → caminho legado do GLPI
 *   POST /inventory, /Inventory
 *
 * Ações suportadas no MVP:
 *   prolog (XML) · contact · get_params · inventory
 * Tarefas anunciadas no CONTACT: apenas "inventory" (netdiscovery/netinventory/esx → fase 2).
 * Referência do protocolo: docs/inventory/protocolo-agente.md
 */
#[AsController]
final readonly class AgentController
{
    public const string SERVER_NAME = 'glpi-bianchi';
    public const string SERVER_VERSION = '0.1.0';

    /** Tarefas que dependem de "inventory" (mesma regra do GLPI Request::contact()). */
    private const array TASKS_NEEDING_INVENTORY = ['esx', 'netdiscovery', 'netinventory', 'remoteinventory'];

    public function __construct(
        private AgentRequestDecoder $decoder,
        private AgentRegistry $agents,
        private InventoryReceiver $receiver,
        private Settings $settings,
        private AgentAccessGuard $guard,
        #[Autowire(service: 'monolog.logger.inventory')]
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/', name: 'agent_root', methods: ['GET', 'POST'])]
    #[Route('/front/inventory.php', name: 'agent_legacy', methods: ['GET', 'POST'])]
    #[Route('/inventory', name: 'agent_inventory', methods: ['GET', 'POST'])]
    #[Route('/Inventory', name: 'agent_inventory_glpi', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            // Navegador na raiz → interface web (o nginx já faz isso; aqui é o fallback).
            if ('/' === $request->getPathInfo()) {
                return new RedirectResponse('/app/', Response::HTTP_FOUND);
            }

            // GET nas rotas do agente: health-check do vhost
            return new Response('glpi-bianchi inventory endpoint', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
        }

        $format = PayloadFormat::Json;
        $compression = Compression::fromContentType($request->headers->get('Content-Type'));
        if (null !== ($denied = $this->guard->deny($request))) {
            $this->logger->warning('Agent request denied', ['reason' => $denied[1], 'ip' => $request->getClientIp()]);
            $response = $this->error($denied[1], $denied[0], $format, $compression)->toHttpResponse();
            if (401 === $denied[0]) {
                $response->headers->set('WWW-Authenticate', 'Basic realm="glpi-bianchi agent"');
            }

            return $response;
        }

        try {
            $agentRequest = $this->decoder->decode($request);
            $format = $agentRequest->format;

            return $this->handle($agentRequest, $request)->toHttpResponse();
        } catch (ProtocolException $e) {
            $this->logger->warning('Agent request rejected', ['error' => $e->getMessage(), 'ip' => $request->getClientIp()]);

            return $this->error($e->getMessage(), $e->getHttpStatus(), $format, $compression)->toHttpResponse();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST, $format, $compression)->toHttpResponse();
        }
    }

    private function handle(AgentRequest $r, Request $http): AgentResponse
    {
        // PROLOG (XML) e CONTACT não precisam de deviceid para responder, mas registramos se houver
        $agent = '' !== $r->deviceId
            ? $this->agents->touch($r, $http->getClientIp(), $http->headers->get('User-Agent'))
            : null;

        return match ($r->action) {
            'prolog' => $this->prolog($r),
            'contact' => $this->contact($r),
            'get_params' => $this->ok($r),
            'inventory' => $this->inventory($r, $agent),
            default => $this->error(\sprintf("Action '%s' is not supported.", $r->action), 400, $r->format, $r->compression),
        };
    }

    private function prolog(AgentRequest $r): AgentResponse
    {
        if ($r->isModernAgent()) {
            return new AgentResponse(['expiration' => $this->settings->inventoryFrequencyHours(), 'status' => 'ok'], PayloadFormat::Json, $r->compression);
        }

        return new AgentResponse(['PROLOG_FREQ' => $this->settings->inventoryFrequencyHours(), 'RESPONSE' => 'SEND'], $r->format, $r->compression);
    }

    private function contact(AgentRequest $r): AgentResponse
    {
        $payload = ['expiration' => $this->settings->inventoryFrequencyHours(), 'status' => 'ok'];

        $enabled = $r->enabledTasks();
        if ([] !== array_intersect($enabled, self::TASKS_NEEDING_INVENTORY) && !\in_array('inventory', $enabled, true)) {
            $enabled[] = 'inventory';
        }

        $disabled = [];
        foreach ($enabled as $task) {
            if ('inventory' === $task) {
                $payload['tasks']['inventory'] = ['server' => self::SERVER_NAME, 'version' => self::SERVER_VERSION];
            } else {
                $disabled[] = $task;
            }
        }
        // O agente desativa localmente as tarefas listadas em "disabled"
        if ([] !== $disabled) {
            $payload['message'] = implode(', ', $disabled).' task(s) not supported';
            $payload['disabled'] = 1 === \count($disabled) ? $disabled[0] : $disabled;
        }

        return new AgentResponse($payload, PayloadFormat::Json, $r->compression);
    }

    private function inventory(AgentRequest $r, ?Agent $agent): AgentResponse
    {
        if (null === $agent) {
            throw new ProtocolException('Missing deviceid.', 400);
        }
        $this->receiver->receive($r, $agent);

        if ($r->isModernAgent() || PayloadFormat::Json === $r->format) {
            return $this->ok($r);
        }

        return new AgentResponse(['RESPONSE' => 'SEND'], $r->format, $r->compression);
    }

    private function ok(AgentRequest $r): AgentResponse
    {
        return new AgentResponse(['expiration' => $this->settings->inventoryFrequencyHours(), 'status' => 'ok'], PayloadFormat::Json, $r->compression);
    }

    private function error(string $message, int $status, PayloadFormat $format, Compression $compression): AgentResponse
    {
        $payload = PayloadFormat::Xml === $format
            ? ['ERROR' => $message]
            : ['status' => 'error', 'message' => $message, 'expiration' => $this->settings->inventoryFrequencyHours()];

        return new AgentResponse($payload, $format, $compression, $status);
    }
}
