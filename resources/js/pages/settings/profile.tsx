import { Form, Head, setLayoutProps, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useIsDemo } from '@/hooks/use-demo';
import { useTranslation } from '@/hooks/use-translation';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
    locales,
    timezones,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    locales: Record<string, string>;
    timezones: string[];
}) {
    const t = useTranslation();
    const isDemo = useIsDemo();
    const { auth } = usePage<PageProps>().props;

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('settings.profile.head_title'),
                href: edit(),
            },
        ],
    });

    const browserLocale =
        typeof navigator !== 'undefined'
            ? navigator.language.split('-')[0]
            : undefined;
    const defaultLocale =
        auth.user.locale ??
        (browserLocale && browserLocale in locales
            ? browserLocale
            : Object.keys(locales)[0]);

    const browserTimezone =
        typeof Intl.DateTimeFormat === 'function'
            ? Intl.DateTimeFormat().resolvedOptions().timeZone
            : 'UTC';
    const defaultTimezone =
        auth.user.timezone ??
        (timezones.includes(browserTimezone) ? browserTimezone : 'UTC');

    return (
        <>
            <Head title={t('settings.profile.head_title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.profile.heading_title')}
                    description={t('settings.profile.heading_description')}
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('settings.profile.name_label')}
                                </Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder={t(
                                        'settings.profile.name_placeholder',
                                    )}
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('settings.profile.email_label')}
                                </Label>

                                {/*
                                 * Read-only on a demo rather than hidden: it
                                 * is the address on the login screen, so the
                                 * visitor should see what they signed in as —
                                 * they just cannot change it out from under
                                 * whoever arrives next. The rest of this form
                                 * stays editable, because switching the
                                 * interface to another language is one of the
                                 * things a demo is for.
                                 */}
                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full read-only:bg-muted read-only:text-muted-foreground"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    readOnly={isDemo}
                                    autoComplete="username"
                                    placeholder={t(
                                        'settings.profile.email_placeholder',
                                    )}
                                />

                                {isDemo && (
                                    <p className="text-xs text-muted-foreground">
                                        {t('demo.email_locked')}
                                    </p>
                                )}

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="timezone">
                                    {t('settings.profile.timezone_label')}
                                </Label>

                                <Select
                                    name="timezone"
                                    defaultValue={defaultTimezone}
                                >
                                    <SelectTrigger
                                        id="timezone"
                                        className="w-full"
                                    >
                                        <SelectValue
                                            placeholder={t(
                                                'settings.profile.timezone_placeholder',
                                            )}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {timezones.map((timezone) => (
                                            <SelectItem
                                                key={timezone}
                                                value={timezone}
                                            >
                                                {timezone}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <InputError
                                    className="mt-2"
                                    message={errors.timezone}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="locale">
                                    {t('settings.profile.locale_label')}
                                </Label>

                                <Select
                                    name="locale"
                                    defaultValue={defaultLocale}
                                >
                                    <SelectTrigger
                                        id="locale"
                                        className="w-full"
                                    >
                                        <SelectValue
                                            placeholder={t(
                                                'settings.profile.locale_placeholder',
                                            )}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(locales).map(
                                            ([value, label]) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>

                                <InputError
                                    className="mt-2"
                                    message={errors.locale}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            {t(
                                                'settings.profile.email_unverified_text',
                                            )}{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                {t(
                                                    'settings.profile.resend_verification_link',
                                                )}
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                {t(
                                                    'settings.profile.verification_link_sent',
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    {t('settings.profile.save')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            {/*
             * Withheld on a demo: deleting this account takes the
             * credentials printed on the login screen with it, and nobody
             * can sign in again until the nightly reset seeds it back.
             */}
            {!isDemo && <DeleteUser />}
        </>
    );
}
