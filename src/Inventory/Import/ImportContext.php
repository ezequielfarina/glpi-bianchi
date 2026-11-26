<?php

declare(strict_types=1);

namespace App\Inventory\Import;

use App\Asset\Entity\Asset;
use App\Asset\Entity\AssetHistory;
use App\Asset\Entity\LockedField;
use App\Asset\Enum\ChangeSource;
use App\Inventory\Entity\RawInventory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Estado de uma importação: inventário de origem, campos travados e coleta de
 * histórico. Uma instância por mensagem processada.
 */
final class ImportContext
{
    /** @var array<string, LockedField> */
    private array $locks = [];

    private int $changes = 0;

    public function __construct(
        public readonly RawInventory $raw,
        public readonly Asset $asset,
        public readonly bool $isNewAsset,
        private readonly EntityManagerInterface $em,
    ) {
        if (!$isNewAsset) {
            /** @var list<LockedField> $locks */
            $locks = $em->createQueryBuilder()
                ->select('l')->from(LockedField::class, 'l')
                ->where('l.asset = :asset OR l.asset IS NULL')
                ->setParameter('asset', $asset)
                ->getQuery()->getResult();
            foreach ($locks as $lock) {
                // trava específica do ativo prevalece sobre a global
                if (!isset($this->locks[$lock->getField()]) || !$lock->isGlobal()) {
                    $this->locks[$lock->getField()] = $lock;
                }
            }
        }
    }

    /** @return array<string, mixed> seção "content" do inventário */
    public function content(): array
    {
        $content = $this->raw->getPayload()['content'] ?? [];

        return \is_array($content) ? $content : [];
    }

    public function isPartial(): bool
    {
        return $this->raw->isPartial();
    }

    public function actor(): string
    {
        return 'agent:'.$this->raw->getDeviceId();
    }

    /**
     * Aplica valor respeitando trava e registrando histórico.
     *
     * @param callable(mixed): void $setter
     */
    public function apply(string $field, mixed $current, mixed $new, callable $setter): void
    {
        if (self::stringify($current) === self::stringify($new)) {
            return;
        }

        if (isset($this->locks[$field])) {
            $this->locks[$field]->setLastRejectedValue(self::stringify($new));

            return;
        }

        $setter($new);
        if (!$this->isNewAsset) {
            $this->log($field, self::stringify($current), self::stringify($new));
        }
    }

    /** Registra evento (ex.: "component.added") — não se aplica a ativo recém-criado. */
    public function event(string $field, ?string $old, ?string $new): void
    {
        if (!$this->isNewAsset) {
            $this->log($field, $old, $new);
        }
    }

    public function changes(): int
    {
        return $this->changes;
    }

    private function log(string $field, ?string $old, ?string $new): void
    {
        ++$this->changes;
        $this->em->persist(new AssetHistory($this->asset, $field, $old, $new, ChangeSource::Inventory, $this->actor()));
    }

    public static function stringify(mixed $value): ?string
    {
        return match (true) {
            null === $value => null,
            \is_object($value) && method_exists($value, 'getName') => (string) $value->getName(),
            $value instanceof \Stringable => (string) $value,
            \is_bool($value) => $value ? '1' : '0',
            \is_scalar($value) => (string) $value,
            default => json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: null,
        };
    }
}
