import { CheckIcon, XIcon } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import type { TranslationKey } from '@/lib/translations';
import { cn } from '@/lib/utils';

type Props = {
    password: string;
};

const LEVELS: {
    labelKey: TranslationKey;
    barColor: string;
    textColor: string;
}[] = [
    {
        labelKey: 'password_strength.level_very_weak',
        barColor: 'bg-destructive',
        textColor: 'text-destructive',
    },
    {
        labelKey: 'password_strength.level_weak',
        barColor: 'bg-orange-500',
        textColor: 'text-orange-500',
    },
    {
        labelKey: 'password_strength.level_fair',
        barColor: 'bg-yellow-500',
        textColor: 'text-yellow-600',
    },
    {
        labelKey: 'password_strength.level_good',
        barColor: 'bg-lime-500',
        textColor: 'text-lime-600',
    },
    {
        labelKey: 'password_strength.level_strong',
        barColor: 'bg-emerald-500',
        textColor: 'text-emerald-600',
    },
];

/**
 * Requirements mirror the backend's Password::defaults() policy (min 12,
 * mixed case, numbers, symbols) so the meter never disagrees with what
 * validation will actually accept. The "uncompromised" (breach) check has
 * no client-side equivalent and is intentionally left out.
 */
function buildRequirements(
    password: string,
    t: (key: TranslationKey) => string,
) {
    return [
        {
            key: 'length',
            met: password.length >= 12,
            label: t('password_strength.requirement_length'),
        },
        {
            key: 'lowercase',
            met: /[a-z]/.test(password),
            label: t('password_strength.requirement_lowercase'),
        },
        {
            key: 'uppercase',
            met: /[A-Z]/.test(password),
            label: t('password_strength.requirement_uppercase'),
        },
        {
            key: 'number',
            met: /[0-9]/.test(password),
            label: t('password_strength.requirement_number'),
        },
        {
            key: 'symbol',
            met: /[^A-Za-z0-9]/.test(password),
            label: t('password_strength.requirement_symbol'),
        },
    ];
}

export default function PasswordStrengthMeter({ password }: Props) {
    const t = useTranslation();

    if (password.length === 0) {
        return null;
    }

    const requirements = buildRequirements(password, t);
    const score = requirements.filter((requirement) => requirement.met).length;
    const level = LEVELS[Math.max(score - 1, 0)];

    return (
        <div className="space-y-2">
            <div className="flex items-center gap-2">
                <div className="flex h-1.5 flex-1 gap-1">
                    {LEVELS.map((_, index) => (
                        <div
                            key={index}
                            className={cn(
                                'h-full flex-1 rounded-full bg-muted transition-colors',
                                index < score && level.barColor,
                            )}
                        />
                    ))}
                </div>
                <span className={cn('text-xs font-medium', level.textColor)}>
                    {t(level.labelKey)}
                </span>
            </div>

            <ul className="grid grid-cols-1 gap-x-4 gap-y-1 text-xs text-muted-foreground sm:grid-cols-2">
                {requirements.map((requirement) => (
                    <li
                        key={requirement.key}
                        className={cn(
                            'flex items-center gap-1.5',
                            requirement.met && 'text-foreground',
                        )}
                    >
                        {requirement.met ? (
                            <CheckIcon className="size-3 shrink-0 text-emerald-500" />
                        ) : (
                            <XIcon className="size-3 shrink-0" />
                        )}
                        {requirement.label}
                    </li>
                ))}
            </ul>
        </div>
    );
}
