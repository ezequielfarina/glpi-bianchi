<?php

declare(strict_types=1);

namespace App\Inventory\Import;

use App\Asset\Entity\Asset;

final readonly class ImportResult
{
    /**
     * @param list<class-string> $sections seções executadas
     */
    public function __construct(
        public Asset $asset,
        public bool $created,
        public int $changes,
        public array $sections,
    ) {
    }
}
