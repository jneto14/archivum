/**
 * Helpers for walking a scheme's node tree by depth.
 *
 * A level's depth is its index in the scheme's level list, never its `position`
 * value: positions are only guaranteed to be ordered, not to start at 1, and
 * arithmetic on them (`position - 1`) silently addressed the wrong depth on a
 * scheme numbered from 0 — offering a floor as the parent of a shelf, and
 * posting a cabinet with no parent at all.
 */

/**
 * Every node sitting at the given depth of the tree, 0 being the root level.
 */
export function nodesAtDepth<T extends { children: T[] }>(
    nodes: T[],
    depth: number,
): T[] {
    if (depth < 0) {
        return [];
    }

    if (depth === 0) {
        return nodes;
    }

    return nodes.flatMap((node) => nodesAtDepth(node.children, depth - 1));
}

/**
 * How many nodes the tree holds, at every depth.
 */
export function countNodes<T extends { children: T[] }>(nodes: T[]): number {
    return nodes.reduce(
        (total, node) => total + 1 + countNodes(node.children),
        0,
    );
}

/**
 * The depth of the topmost level that has no nodes yet, or null when every
 * level has at least one. It is where a user filling an empty archive has to
 * add the next node, since a level can only be filled once the one above it is.
 */
export function firstEmptyDepth<T extends { children: T[] }>(
    levelCount: number,
    nodes: T[],
): number | null {
    for (let depth = 0; depth < levelCount; depth++) {
        if (nodesAtDepth(nodes, depth).length === 0) {
            return depth;
        }
    }

    return null;
}
