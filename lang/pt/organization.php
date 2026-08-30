<?php

declare(strict_types=1);

return [
    'levels_required' => 'É necessário pelo menos um nível.',
    'duplicate_level_keys' => 'As chaves de nível devem ser únicas dentro de um esquema.',
    'alphabetical_capacity_max' => 'Um nível que usa a estratégia Alfabética não pode ter uma capacidade superior a 26 (A–Z).',
    'capacity_reached' => 'Este nível atingiu a sua capacidade configurada.',
    'value_required' => 'É necessário um valor para níveis que usam a estratégia Manual.',
    'root_node_cannot_have_parent' => 'Um nó no primeiro nível não pode ter um nó pai.',
    'invalid_parent_level' => 'O nó pai deve pertencer ao nível imediatamente anterior do mesmo esquema.',
    'duplicate_node_value' => 'Já existe um nó com este valor neste nível sob o mesmo pai.',
    'invalid_rule_target_level' => 'O nível de destino deve pertencer ao mesmo esquema.',
    'duplicate_rule_matcher' => 'Já existe uma regra para este critério dentro do esquema.',
    'migration_target_must_differ' => 'A localização de destino tem de ser diferente da localização de origem.',
    'scheme_already_exists' => 'Este workspace já tem um esquema de organização.',
    'scheme_created' => 'Esquema de organização criado.',
    'scheme_updated' => 'Esquema de organização atualizado.',
    'node_created' => 'Localização adicionada.',
    'node_deleted' => 'Localização eliminada.',
    'node_has_children' => 'Esta localização ainda tem localizações filhas e não pode ser eliminada.',
    'node_has_documents' => 'Esta localização ainda tem documentos atribuídos. Migre-os para outra localização primeiro.',
    'level_created' => 'Nível adicionado.',
    'level_deleted' => 'Nível eliminado.',
    'level_not_last' => 'Só o último nível de um esquema pode ser removido.',
    'level_has_nodes' => 'Este nível ainda tem localizações e não pode ser removido.',
    'rule_created' => 'Regra criada.',
    'rule_updated' => 'Regra atualizada.',
    'rule_deleted' => 'Regra eliminada.',
    'migration_queued' => 'Os documentos estão a ser movidos.',
];
