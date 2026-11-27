<?php

declare(strict_types=1);

namespace App\Audit\Service;

/**
 * Ações auditadas (ADR-0015): rota → [chave da ação, descrição, módulo, tipo de entidade, parâmetro do id].
 *
 * Toda escrita em /api/ui (POST/PUT/PATCH/DELETE) é auditada; a rota que não estiver aqui é gravada com a
 * chave genérica do nome da rota (ver AuditRecorder::describe) — rota nova nunca fica sem trilha.
 * Login/2FA/logout ficam em `auth_event` (ADR-0011) e não entram aqui.
 */
final class AuditCatalog
{
    public const array MODULES = [
        'assets' => 'Ativos', 'branches' => 'Matriz e filiais', 'suppliers' => 'Fornecedores', 'contracts' => 'Contratos',
        'licenses' => 'Licenças', 'attachments' => 'Anexos', 'lookups' => 'Cadastros auxiliares', 'stock' => 'Estoque',
        'invoices' => 'Notas fiscais', 'tools' => 'Ferramentas', 'monitor' => 'Monitoramento', 'users' => 'Usuários',
        'account' => 'Minha conta', 'settings' => 'Configurações', 'agents' => 'Agentes', 'other' => 'Outros',
    ];

    /** @var array<string, array{0: string, 1: string, 2: string, 3: string|null, 4: string|null}> */
    public const array ROUTES = [
        // ativos
        'ui_asset_create' => ['asset.created', 'Ativo cadastrado', 'assets', 'asset', null],
        'ui_asset_update' => ['asset.updated', 'Ativo alterado', 'assets', 'asset', 'uuid'],
        'ui_asset_delete' => ['asset.deleted', 'Ativo excluído', 'assets', 'asset', 'uuid'],
        'ui_agent_wakeup' => ['agent.wakeup', 'Inventário forçado no agente', 'agents', 'agent', 'deviceId'],
        // matriz e filiais
        'ui_manage_branch_create' => ['branch.created', 'Filial cadastrada', 'branches', 'branch', null],
        'ui_manage_branch_update' => ['branch.updated', 'Filial alterada', 'branches', 'branch', 'id'],
        'ui_manage_branch_delete' => ['branch.deleted', 'Filial excluída', 'branches', 'branch', 'id'],
        'ui_manage_branch_assets' => ['branch.assets_linked', 'Ativos vinculados à filial', 'branches', 'branch', 'id'],
        // fornecedores e contatos
        'ui_manage_supplier_create' => ['supplier.created', 'Fornecedor cadastrado', 'suppliers', 'supplier', null],
        'ui_manage_supplier_update' => ['supplier.updated', 'Fornecedor alterado', 'suppliers', 'supplier', 'id'],
        'ui_manage_supplier_delete' => ['supplier.deleted', 'Fornecedor excluído', 'suppliers', 'supplier', 'id'],
        'ui_manage_contact_create' => ['contact.created', 'Contato cadastrado', 'suppliers', 'supplier', 'id'],
        'ui_manage_contact_update' => ['contact.updated', 'Contato alterado', 'suppliers', 'contact', 'id'],
        'ui_manage_contact_delete' => ['contact.deleted', 'Contato excluído', 'suppliers', 'contact', 'id'],
        // contratos
        'ui_manage_contract_create' => ['contract.created', 'Contrato cadastrado', 'contracts', 'contract', null],
        'ui_manage_contract_update' => ['contract.updated', 'Contrato alterado', 'contracts', 'contract', 'id'],
        'ui_manage_contract_delete' => ['contract.deleted', 'Contrato excluído', 'contracts', 'contract', 'id'],
        'ui_manage_contract_link' => ['contract.asset_linked', 'Ativo vinculado ao contrato', 'contracts', 'contract', 'id'],
        'ui_manage_contract_unlink' => ['contract.asset_unlinked', 'Ativo desvinculado do contrato', 'contracts', 'contract', 'id'],
        // licenças
        'ui_manage_license_create' => ['license.created', 'Licença cadastrada', 'licenses', 'license', null],
        'ui_manage_license_update' => ['license.updated', 'Licença alterada', 'licenses', 'license', 'id'],
        'ui_manage_license_delete' => ['license.deleted', 'Licença excluída', 'licenses', 'license', 'id'],
        'ui_manage_license_key' => ['license.key_viewed', 'Chave da licença exibida', 'licenses', 'license', 'id'],
        'ui_manage_license_assign' => ['license.assigned', 'Licença atribuída', 'licenses', 'license', 'id'],
        'ui_manage_license_unassign' => ['license.unassigned', 'Atribuição de licença removida', 'licenses', 'license_assignment', 'id'],
        // anexos
        'ui_attachment_upload' => ['attachment.uploaded', 'Anexo enviado', 'attachments', 'attachment', null],
        'ui_attachment_delete' => ['attachment.deleted', 'Anexo excluído', 'attachments', 'attachment', 'uuid'],
        // cadastros auxiliares
        'ui_manage_lookup_create' => ['lookup.created', 'Item de cadastro auxiliar criado', 'lookups', 'lookup', null],
        'ui_manage_lookup_update' => ['lookup.updated', 'Item de cadastro auxiliar alterado', 'lookups', 'lookup', 'id'],
        'ui_manage_lookup_delete' => ['lookup.deleted', 'Item de cadastro auxiliar excluído', 'lookups', 'lookup', 'id'],
        // estoque e notas
        'ui_stock_item_create' => ['stock.item_created', 'Item de estoque cadastrado', 'stock', 'stock_item', null],
        'ui_stock_item_update' => ['stock.item_updated', 'Item de estoque alterado', 'stock', 'stock_item', 'id'],
        'ui_stock_item_delete' => ['stock.item_deleted', 'Item de estoque excluído/desativado', 'stock', 'stock_item', 'id'],
        'ui_stock_in' => ['stock.in', 'Entrada manual no estoque', 'stock', null, null],
        'ui_stock_out' => ['stock.out', 'Saída para filial', 'stock', 'branch', null],
        'ui_stock_adjust' => ['stock.adjusted', 'Ajuste de estoque', 'stock', 'stock_item', null],
        'ui_stock_invoice_create' => ['invoice.created', 'Nota fiscal registrada', 'invoices', 'invoice', null],
        'ui_stock_invoice_update' => ['invoice.updated', 'Nota fiscal alterada', 'invoices', 'invoice', 'id'],
        'ui_stock_invoice_status' => ['invoice.status', 'Situação da nota alterada', 'invoices', 'invoice', 'id'],
        'ui_stock_invoice_delete' => ['invoice.deleted', 'Nota fiscal excluída', 'invoices', 'invoice', 'id'],
        // ferramentas
        'tools_scanner_create' => ['scan.started', 'Varredura de rede iniciada', 'tools', 'scan', null],
        'tools_scanner_cancel' => ['scan.cancelled', 'Varredura cancelada', 'tools', 'scan', 'id'],
        'tools_scanner_delete' => ['scan.deleted', 'Varredura excluída', 'tools', 'scan', 'id'],
        'tools_net_ping' => ['net.ping', 'Ping', 'tools', null, null],
        'tools_net_trace' => ['net.trace', 'Traceroute/MTR', 'tools', null, null],
        'tools_net_ports' => ['net.ports', 'Teste de portas', 'tools', null, null],
        'tools_net_dns' => ['net.dns', 'Consulta DNS', 'tools', null, null],
        'tools_net_asn' => ['net.asn', 'Consulta ASN', 'tools', null, null],
        'tools_net_rdap' => ['net.rdap', 'Consulta whois/RDAP', 'tools', null, null],
        'tools_net_mac' => ['net.mac', 'Consulta de fabricante por MAC', 'tools', null, null],
        // monitoramento
        'tools_monitor_schedule_create' => ['schedule.created', 'Agendamento de varredura criado', 'monitor', 'schedule', null],
        'tools_monitor_schedule_update' => ['schedule.updated', 'Agendamento de varredura alterado', 'monitor', 'schedule', 'id'],
        'tools_monitor_schedule_delete' => ['schedule.deleted', 'Agendamento de varredura excluído', 'monitor', 'schedule', 'id'],
        'tools_monitor_schedule_run' => ['schedule.run', 'Agendamento executado agora', 'monitor', 'schedule', 'id'],
        'tools_monitor_alerts_ack' => ['alerts.acknowledged', 'Alertas reconhecidos', 'monitor', null, null],
        'tools_monitor_channel_create' => ['channel.created', 'Canal de notificação criado', 'monitor', 'channel', null],
        'tools_monitor_channel_update' => ['channel.updated', 'Canal de notificação alterado', 'monitor', 'channel', 'id'],
        'tools_monitor_channel_delete' => ['channel.deleted', 'Canal de notificação excluído', 'monitor', 'channel', 'id'],
        'tools_monitor_channel_test' => ['channel.tested', 'Canal de notificação testado', 'monitor', 'channel', 'id'],
        // usuários e conta
        'admin_user_create' => ['user.created', 'Usuário criado', 'users', 'user', null],
        'admin_user_update' => ['user.updated', 'Usuário alterado', 'users', 'user', 'id'],
        'admin_user_delete' => ['user.deleted', 'Usuário excluído', 'users', 'user', 'id'],
        'admin_user_reset_password' => ['user.password_reset', 'Senha temporária gerada', 'users', 'user', 'id'],
        'admin_user_reset_mfa' => ['user.mfa_reset', '2FA do usuário desligado', 'users', 'user', 'id'],
        'admin_user_unlock' => ['user.unlocked', 'Usuário desbloqueado', 'users', 'user', 'id'],
        'admin_user_sessions_revoke' => ['user.sessions_revoked', 'Sessões do usuário encerradas', 'users', 'user', 'id'],
        'me_password' => ['me.password_changed', 'Trocou a própria senha', 'account', null, null],
        'me_session_revoke' => ['me.session_revoked', 'Encerrou uma sessão própria', 'account', null, null],
        'me_mfa_setup' => ['me.mfa_setup', 'Iniciou configuração do 2FA', 'account', null, null],
        'me_mfa_enable' => ['me.mfa_enabled', 'Ativou o 2FA', 'account', null, null],
        'me_mfa_disable' => ['me.mfa_disabled', 'Desativou o 2FA', 'account', null, null],
        // configurações
        'ui_settings_update' => ['settings.updated', 'Configurações alteradas', 'settings', null, null],
    ];

    /** Leituras sensíveis também auditadas (GET). */
    public const array AUDITED_READS = ['ui_manage_license_key'];

    /** Rotas fora da trilha (auth_event já registra). */
    public const array EXCLUDED = ['auth_login', 'auth_mfa', 'auth_logout'];
}
