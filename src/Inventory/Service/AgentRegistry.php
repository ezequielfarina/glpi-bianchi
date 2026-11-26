<?php

declare(strict_types=1);

namespace App\Inventory\Service;

use App\Inventory\Entity\Agent;
use App\Inventory\Protocol\AgentRequest;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Cadastro/atualização do agente a cada contato (equivalente a Inventory::contact() do GLPI).
 */
final readonly class AgentRegistry
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
    ) {
    }

    public function touch(AgentRequest $request, ?string $remoteIp, ?string $userAgent): Agent
    {
        if ('' === $request->deviceId) {
            throw new \InvalidArgumentException('Missing deviceid.');
        }

        $agent = $this->em->getRepository(Agent::class)->findOneBy(['deviceId' => $request->deviceId]);
        $isNew = null === $agent;
        $agent ??= new Agent($request->deviceId);

        $agent->touch($remoteIp, $userAgent, $request->agentId);
        if ([] !== $request->enabledTasks()) {
            $agent->setEnabledTasks($request->enabledTasks());
        }
        if (null !== $request->tag()) {
            $agent->setTag($request->tag());
        }
        if (\is_string($request->data['versionclient'] ?? null)) {
            $agent->setVersion($request->data['versionclient']);
        } elseif (\is_string($request->data['content']['versionclient'] ?? null)) {
            $agent->setVersion($request->data['content']['versionclient']);
        }

        try {
            if ($isNew) {
                $this->em->persist($agent);
            }
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Corrida: dois requests do mesmo agente criaram o registro ao mesmo tempo.
            $this->registry->resetManager();

            return $this->touch($request, $remoteIp, $userAgent);
        }

        return $agent;
    }
}
