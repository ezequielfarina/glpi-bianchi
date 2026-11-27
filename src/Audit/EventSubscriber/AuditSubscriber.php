<?php

declare(strict_types=1);

namespace App\Audit\EventSubscriber;

use App\Audit\Service\AuditCatalog;
use App\Audit\Service\AuditRecorder;
use App\Identity\Security\CurrentActor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Audita toda ação da interface (ADR-0015): escritas em /api/ui e leituras sensíveis do catálogo.
 * - antes do controller: guarda o objeto da ação (nome/patrimônio/NF…) — para exclusões é a única chance;
 * - depois: só grava se a resposta for 2xx (ação que falhou não aconteceu; tentativas negadas ficam no log).
 */
final readonly class AuditSubscriber implements EventSubscriberInterface
{
    private const string ATTR = '_audit';

    public function __construct(
        private AuditRecorder $recorder,
        private CurrentActor $actor,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', -64],
            KernelEvents::RESPONSE => ['onResponse', -64],
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/ui/')) {
            return;
        }
        $route = (string) $request->attributes->get('_route');
        $write = \in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if ('' === $route || \in_array($route, AuditCatalog::EXCLUDED, true) || (!$write && !\in_array($route, AuditCatalog::AUDITED_READS, true))) {
            return;
        }
        [, , , $type, $param] = AuditRecorder::describe($route, $request->getMethod());
        $id = null === $param ? null : $request->attributes->get($param);
        $id = \is_scalar($id) ? (string) $id : null;
        $request->attributes->set(self::ATTR, [
            'route' => $route,
            'type' => $type,
            'id' => $id,
            'subject' => $this->recorder->subject($type, $id),
            'data' => AuditRecorder::requestData($request, str_starts_with($route, 'tools_monitor_channel_')),
        ]);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $ctx = $request->attributes->get(self::ATTR);
        $status = $event->getResponse()->getStatusCode();
        if (!\is_array($ctx) || $status < 200 || $status >= 300) {
            return;
        }
        /** @var array{route: string, type: string|null, id: string|null, subject: string|null, data: array<string, mixed>} $ctx */
        $request->attributes->remove(self::ATTR);
        $details = [];
        if ([] !== $ctx['data']) {
            $details['request'] = $ctx['data'];
        }
        $type = $ctx['type'];
        $id = $ctx['id'];
        // criação: o id vem na resposta ({id}, {uuid}, {batch}, {scan_id})
        $content = (string) $event->getResponse()->getContent();
        $body = str_starts_with(ltrim($content), '{') ? json_decode($content, true) : null;
        if (\is_array($body)) {
            $result = array_intersect_key($body, array_flip(['id', 'uuid', 'batch', 'scan_id', 'result', 'status', 'acknowledged', 'assets', 'deleted']));
            if ([] !== $result) {
                $details['result'] = AuditRecorder::scrub($result);
            }
            if (null === $id) {
                foreach (['uuid', 'id', 'scan_id'] as $k) {
                    if (isset($body[$k]) && \is_scalar($body[$k])) {
                        $id = (string) $body[$k];
                        break;
                    }
                }
            }
        }
        // saída para filial / ajuste: o objeto está no corpo
        if (null === $id && \in_array($ctx['route'], ['ui_stock_out', 'ui_stock_adjust'], true)) {
            $key = 'ui_stock_out' === $ctx['route'] ? 'branch_id' : 'stock_item_id';
            $v = $ctx['data'][$key] ?? null;
            $id = \is_scalar($v) ? (string) $v : null;
        }
        $subject = $ctx['subject'] ?? $this->recorder->subject($type, $id);
        if (null === $subject && \in_array($ctx['route'], ['ui_manage_lookup_create', 'ui_manage_lookup_update', 'ui_manage_lookup_delete'], true)) {
            $kind = $request->attributes->get('kind');
            $name = $ctx['data']['name'] ?? $ctx['data']['label'] ?? null;
            $subject = trim((\is_scalar($kind) ? (string) $kind : '').(\is_scalar($name) ? ': '.$name : ''));
        }
        $account = $this->actor->account();
        $this->recorder->record($request, $ctx['route'], $status, $account?->id, $this->actor->name(), null === $id ? null : $type, $id, $subject ?: null, $details);
    }
}
