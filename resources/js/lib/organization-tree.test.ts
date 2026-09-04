import { describe, expect, it } from 'vitest';
import {
    countNodes,
    firstEmptyDepth,
    nodesAtDepth,
} from '@/lib/organization-tree';

type Node = { id: string; children: Node[] };

const node = (id: string, children: Node[] = []): Node => ({ id, children });

/** Floor 1 → Cabinet A → Shelf 001, the shape the demo archive has. */
const tree = [node('floor', [node('cabinet', [node('shelf')])])];

describe('nodesAtDepth', () => {
    it('returns the roots at depth 0 and descends from there', () => {
        expect(nodesAtDepth(tree, 0).map((n) => n.id)).toEqual(['floor']);
        expect(nodesAtDepth(tree, 1).map((n) => n.id)).toEqual(['cabinet']);
        expect(nodesAtDepth(tree, 2).map((n) => n.id)).toEqual(['shelf']);
    });

    it('returns nothing below the tree or above its root', () => {
        expect(nodesAtDepth(tree, 3)).toEqual([]);
        // A depth derived by arithmetic can go negative; asking for the parents
        // of the root level must come back empty rather than the whole tree.
        expect(nodesAtDepth(tree, -1)).toEqual([]);
    });
});

describe('countNodes', () => {
    it('counts every node at every depth', () => {
        expect(countNodes(tree)).toBe(3);
        expect(countNodes([])).toBe(0);
    });
});

describe('firstEmptyDepth', () => {
    it('finds the topmost level with nothing in it', () => {
        expect(firstEmptyDepth(3, [node('floor')])).toBe(1);
        expect(firstEmptyDepth(3, [])).toBe(0);
    });

    it('is null once every level holds something', () => {
        expect(firstEmptyDepth(3, tree)).toBeNull();
    });
});
