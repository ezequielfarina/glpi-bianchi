<?php

declare(strict_types=1);

namespace App\Inventory\Import\Section;

use App\Inventory\Import\ImportContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Um importador por seção do inventário (cpus, memories, softwares...).
 *
 * Regra de inventário parcial: se a seção não veio no payload e o inventário é
 * parcial, a seção é ignorada; se é completo, a ausência significa "vazio" e os
 * itens dinâmicos existentes são removidos.
 *
 * Para adicionar suporte a uma nova seção: criar a classe implementando esta
 * interface — o autoconfigure registra no ComputerImporter (ordem por priority()).
 */
#[AutoconfigureTag('app.inventory.section')]
interface SectionImporter
{
    /** Chaves de `content` que esta seção consome (ex.: ['cpus']). */
    public function sections(): array;

    /** Ordem de execução: maior roda antes (hardware/bios precisam vir primeiro). */
    public static function priority(): int;

    /**
     * @param array<string, mixed> $content seção "content" completa
     */
    public function import(array $content, ImportContext $context): void;
}
