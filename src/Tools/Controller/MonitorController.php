<?php

declare(strict_types=1);

namespace App\Tools\Controller;

use App\Identity\Security\CurrentActor;
use App\Tools\Alert\AlertService;
use App\Tools\Alert\ChannelService;
use App\Tools\Alert\Notifier;
use App\Tools\Service\ScheduleService;
use App\Ui\Controller\UiJson;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ferramentas → Monitoramento (ADR-0013): varreduras agendadas, alertas e canais de notificação.
 * Acesso: Técnico/Administrador; canais só Administrador (security.yaml).
 */
#[AsController]
#[Route('/api/ui/tools', name: 'tools_monitor_')]
final readonly class MonitorController
{
    use UiJson;

    public function __construct(
        private ScheduleService $schedules,
        private AlertService $alerts,
        private ChannelService $channels,
        private Notifier $notifier,
        private CurrentActor $actor,
    ) {
    }

    #[Route('/schedules', name: 'schedules', methods: ['GET'])]
    public function schedules(): JsonResponse
    {
        return $this->json(['items' => $this->schedules->list(), 'alert_defaults' => ScheduleService::ALERT_DEFAULTS]);
    }

    #[Route('/schedules', name: 'schedule_create', methods: ['POST'])]
    public function createSchedule(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->schedules->create($this->body($request), $this->actor->name())], Response::HTTP_CREATED);
    }

    #[Route('/schedules/{id}', name: 'schedule_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateSchedule(int $id, Request $request): JsonResponse
    {
        $this->schedules->update($id, $this->body($request));

        return $this->json(['id' => $id]);
    }

    #[Route('/schedules/{id}', name: 'schedule_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteSchedule(int $id): JsonResponse
    {
        $this->schedules->delete($id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/schedules/{id}/run', name: 'schedule_run', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function runSchedule(int $id): JsonResponse
    {
        return $this->json(['scan_id' => $this->schedules->runNow($id, $this->actor->name())], Response::HTTP_ACCEPTED);
    }

    #[Route('/alerts', name: 'alerts', methods: ['GET'])]
    public function alerts(Request $request): JsonResponse
    {
        return $this->json($this->alerts->list($request->query->all()));
    }

    #[Route('/alerts/count', name: 'alerts_count', methods: ['GET'])]
    public function alertCount(): JsonResponse
    {
        return $this->json($this->alerts->counts());
    }

    #[Route('/alerts/ack', name: 'alerts_ack', methods: ['POST'])]
    public function acknowledge(Request $request): JsonResponse
    {
        $b = $this->body($request);
        $ids = true === ($b['all'] ?? false) ? null : array_values(array_filter((array) ($b['ids'] ?? []), 'is_int'));
        $kind = isset($b['kind']) && \is_string($b['kind']) ? $b['kind'] : null;

        return $this->json(['acknowledged' => $this->alerts->acknowledge($ids, $this->actor->name(), $kind)]);
    }

    #[Route('/channels', name: 'channels', methods: ['GET'])]
    public function channels(): JsonResponse
    {
        return $this->json(['items' => $this->channels->list()]);
    }

    #[Route('/channels', name: 'channel_create', methods: ['POST'])]
    public function createChannel(Request $request): JsonResponse
    {
        return $this->json(['id' => $this->channels->save(null, $this->body($request))], Response::HTTP_CREATED);
    }

    #[Route('/channels/{id}', name: 'channel_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateChannel(int $id, Request $request): JsonResponse
    {
        return $this->json(['id' => $this->channels->save($id, $this->body($request))]);
    }

    #[Route('/channels/{id}', name: 'channel_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteChannel(int $id): JsonResponse
    {
        $this->channels->delete($id);

        return $this->json(['deleted' => true]);
    }

    #[Route('/channels/{id}/test', name: 'channel_test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function testChannel(int $id): JsonResponse
    {
        return $this->json($this->notifier->test($id));
    }
}
