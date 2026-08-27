<?php

declare(strict_types=1);

return [
    'levels_required' => 'At least one level is required.',
    'duplicate_level_keys' => 'Level keys must be unique within a scheme.',
    'capacity_reached' => 'This level has reached its configured capacity.',
    'value_required' => 'A value is required for levels using the Manual strategy.',
    'root_node_cannot_have_parent' => 'A node at the first level cannot have a parent.',
    'invalid_parent_level' => 'The parent node must belong to the immediately preceding level of the same scheme.',
    'duplicate_node_value' => 'A node with this value already exists at this level under the same parent.',
    'invalid_rule_target_level' => 'The target level must belong to the same scheme.',
    'duplicate_rule_matcher' => 'A rule already exists for this matcher within the scheme.',
    'migration_target_must_differ' => 'The target scheme must be different from the source scheme.',
];
