export type Workspace = {
    id: string;
    name: string;
};

export type WorkspaceMembership = Workspace & {
    role: 'admin' | 'user';
};

export type WorkspaceSummary = Workspace & {
    usersCount: number;
    createdAtDiff: string | null;
};
