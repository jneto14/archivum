import { Head, router, setLayoutProps, usePage } from '@inertiajs/react';
import { FolderTreeIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    create as schemeCreate,
    index as schemesIndex,
    show as schemeShow,
} from '@/routes/organization/schemes';

type SchemeRow = {
    id: string;
    name: string;
    levels_count: number;
    rules_count: number;
};

type Props = {
    schemes: SchemeRow[];
    canManage: boolean;
};

export default function OrganizationIndex({ schemes, canManage }: Props) {
    const { workspace } = usePage().props;

    setLayoutProps({
        breadcrumbs: [
            {
                title: 'Organization',
                href: workspace ? schemesIndex.url(workspace.id) : '#',
            },
        ],
    });

    if (!workspace) {
        return null;
    }

    return (
        <>
            <Head title="Organization" />

            <div className="space-y-6 p-6">
                <div className="flex items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Organization schemes
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {schemes.length} scheme
                            {schemes.length === 1 ? '' : 's'}
                        </p>
                    </div>
                    {canManage && (
                        <Button
                            size="sm"
                            onClick={() =>
                                router.visit(schemeCreate.url(workspace.id))
                            }
                        >
                            New scheme
                        </Button>
                    )}
                </div>

                {schemes.length === 0 && (
                    <div className="rounded-xl border border-dashed p-12 text-center">
                        <FolderTreeIcon className="mx-auto mb-2 size-8 text-muted-foreground" />
                        <div className="font-semibold">
                            No organization schemes yet
                        </div>
                        <div className="text-sm text-muted-foreground">
                            {canManage
                                ? 'Create a scheme to define how documents are physically filed.'
                                : 'An admin has not configured a filing scheme for this workspace yet.'}
                        </div>
                    </div>
                )}

                {schemes.length > 0 && (
                    <div className="overflow-hidden rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Scheme</TableHead>
                                    <TableHead>Levels</TableHead>
                                    <TableHead>Rules</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {schemes.map((scheme) => (
                                    <TableRow
                                        key={scheme.id}
                                        className="cursor-pointer"
                                        onClick={() =>
                                            router.visit(
                                                schemeShow.url(scheme.id),
                                            )
                                        }
                                    >
                                        <TableCell className="font-medium">
                                            {scheme.name}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="secondary">
                                                {scheme.levels_count}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="secondary">
                                                {scheme.rules_count}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>
        </>
    );
}
