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
        'account' => 'Minha conta', 'settings' => 'Configurações', 'agents' => 'Agentes',
        'dcim' => 'Infraestrutura física', 'cabling' => 'Cabeamento', 'ipam' => 'IPAM', 'circuits' => 'Circuitos', 'other' => 'Outros',
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
        'ui_stock_shipment_print' => ['stock.label_printed', 'Etiqueta de envio impressa', 'stock', null, null],
        'ui_stock_label_printer_test' => ['stock.label_test', 'Etiqueta de teste impressa', 'stock', null, null],
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
        // infraestrutura física, cabeamento, IPAM e circuitos (ADR-0016)
        'ui_dcim_location_create' => ['location.created', 'Local cadastrado', 'dcim', 'location', null],
        'ui_dcim_location_update' => ['location.updated', 'Local alterado', 'dcim', 'location', 'id'],
        'ui_dcim_location_delete' => ['location.deleted', 'Local excluído', 'dcim', 'location', 'id'],
        'ui_dcim_rack_create' => ['rack.created', 'Rack cadastrado', 'dcim', 'rack', null],
        'ui_dcim_rack_update' => ['rack.updated', 'Rack alterado', 'dcim', 'rack', 'id'],
        'ui_dcim_rack_delete' => ['rack.deleted', 'Rack excluído', 'dcim', 'rack', 'id'],
        'ui_dcim_role_create' => ['device_role.created', 'Função de equipamento cadastrada', 'dcim', 'device_role', null],
        'ui_dcim_role_update' => ['device_role.updated', 'Função de equipamento alterada', 'dcim', 'device_role', 'id'],
        'ui_dcim_role_delete' => ['device_role.deleted', 'Função de equipamento excluída', 'dcim', 'device_role', 'id'],
        'ui_dcim_type_create' => ['device_type.created', 'Tipo de equipamento cadastrado', 'dcim', 'device_type', null],
        'ui_dcim_type_update' => ['device_type.updated', 'Tipo de equipamento alterado', 'dcim', 'device_type', 'id'],
        'ui_dcim_type_delete' => ['device_type.deleted', 'Tipo de equipamento excluído', 'dcim', 'device_type', 'id'],
        'ui_dcim_template_create' => ['port_template.created', 'Modelos de porta criados', 'dcim', 'device_type', 'id'],
        'ui_dcim_template_update' => ['port_template.updated', 'Modelo de porta alterado', 'dcim', 'port_template', 'id'],
        'ui_dcim_template_delete' => ['port_template.deleted', 'Modelo de porta excluído', 'dcim', 'port_template', 'id'],
        'ui_dcim_device_create' => ['device.created', 'Equipamento cadastrado', 'dcim', 'device', null],
        'ui_dcim_device_update' => ['device.updated', 'Equipamento alterado', 'dcim', 'device', 'id'],
        'ui_dcim_device_delete' => ['device.deleted', 'Equipamento excluído', 'dcim', 'device', 'id'],
        'ui_dcim_port_create' => ['port.created', 'Portas criadas', 'dcim', 'device', 'id'],
        'ui_dcim_port_update' => ['port.updated', 'Porta alterada', 'dcim', 'port', 'id'],
        'ui_dcim_port_delete' => ['port.deleted', 'Porta excluída', 'dcim', 'port', 'id'],
        'ui_dcim_port_bulk_delete' => ['port.bulk_deleted', 'Portas excluídas em lote', 'dcim', null, null],
        'ui_dcim_cable_create' => ['cable.created', 'Cabo cadastrado', 'cabling', 'cable', null],
        'ui_dcim_cable_update' => ['cable.updated', 'Cabo alterado', 'cabling', 'cable', 'id'],
        'ui_dcim_cable_delete' => ['cable.deleted', 'Cabo excluído', 'cabling', 'cable', 'id'],
        'ui_ipam_prefix_create' => ['prefix.created', 'Prefixo cadastrado', 'ipam', 'prefix', null],
        'ui_ipam_prefix_update' => ['prefix.updated', 'Prefixo alterado', 'ipam', 'prefix', 'id'],
        'ui_ipam_prefix_delete' => ['prefix.deleted', 'Prefixo excluído', 'ipam', 'prefix', 'id'],
        'ui_ipam_range_create' => ['ip_range.created', 'Faixa de IP cadastrada', 'ipam', 'ip_range', null],
        'ui_ipam_range_update' => ['ip_range.updated', 'Faixa de IP alterada', 'ipam', 'ip_range', 'id'],
        'ui_ipam_range_delete' => ['ip_range.deleted', 'Faixa de IP excluída', 'ipam', 'ip_range', 'id'],
        'ui_ipam_ip_create' => ['ip_address.created', 'Endereço IP cadastrado', 'ipam', 'ip_address', null],
        'ui_ipam_ip_update' => ['ip_address.updated', 'Endereço IP alterado', 'ipam', 'ip_address', 'id'],
        'ui_ipam_ip_delete' => ['ip_address.deleted', 'Endereço IP excluído', 'ipam', 'ip_address', 'id'],
        'ui_ipam_vrf_create' => ['vrf.created', 'VRF cadastrada', 'ipam', 'vrf', null],
        'ui_ipam_vrf_update' => ['vrf.updated', 'VRF alterada', 'ipam', 'vrf', 'id'],
        'ui_ipam_vrf_delete' => ['vrf.deleted', 'VRF excluída', 'ipam', 'vrf', 'id'],
        'ui_ipam_vlan_group_create' => ['vlan_group.created', 'Grupo de VLANs cadastrado', 'ipam', 'vlan_group', null],
        'ui_ipam_vlan_group_update' => ['vlan_group.updated', 'Grupo de VLANs alterado', 'ipam', 'vlan_group', 'id'],
        'ui_ipam_vlan_group_delete' => ['vlan_group.deleted', 'Grupo de VLANs excluído', 'ipam', 'vlan_group', 'id'],
        'ui_ipam_vlan_create' => ['vlan.created', 'VLAN cadastrada', 'ipam', 'vlan', null],
        'ui_ipam_vlan_update' => ['vlan.updated', 'VLAN alterada', 'ipam', 'vlan', 'id'],
        'ui_ipam_vlan_delete' => ['vlan.deleted', 'VLAN excluída', 'ipam', 'vlan', 'id'],
        'ui_circuit_type_create' => ['circuit_type.created', 'Tipo de circuito cadastrado', 'circuits', 'circuit_type', null],
        'ui_circuit_type_update' => ['circuit_type.updated', 'Tipo de circuito alterado', 'circuits', 'circuit_type', 'id'],
        'ui_circuit_type_delete' => ['circuit_type.deleted', 'Tipo de circuito excluído', 'circuits', 'circuit_type', 'id'],
        'ui_circuit_create' => ['circuit.created', 'Circuito cadastrado', 'circuits', 'circuit', null],
        'ui_circuit_update' => ['circuit.updated', 'Circuito alterado', 'circuits', 'circuit', 'id'],
        'ui_circuit_delete' => ['circuit.deleted', 'Circuito excluído', 'circuits', 'circuit', 'id'],
        'ui_circuit_termination_save' => ['circuit.termination_saved', 'Terminação de circuito salva', 'circuits', 'circuit', 'id'],
        'ui_circuit_termination_delete' => ['circuit.termination_deleted', 'Terminação de circuito excluída', 'circuits', 'circuit_termination', 'id'],
        // configurações
        'ui_settings_update' => ['settings.updated', 'Configurações alteradas', 'settings', null, null],
    ];

    /** Leituras sensíveis também auditadas (GET). */
    public const array AUDITED_READS = ['ui_manage_license_key'];

    /** Rotas fora da trilha (auth_event já registra). */
    public const array EXCLUDED = ['auth_login', 'auth_mfa', 'auth_logout'];
}
