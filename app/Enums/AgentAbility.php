<?php

namespace App\Enums;

enum AgentAbility: string
{
    case ContextRead = 'context.read';
    case AccountsRead = 'zernio.accounts.read';
    case PostsRead = 'zernio.posts.read';
    case AnalyticsRead = 'zernio.analytics.read';
    case OperationsRead = 'operations.read';
    case PostsPublish = 'zernio.posts.publish';
    case PostsSchedule = 'zernio.posts.schedule';

    /** @return list<string> */
    public static function readOnly(): array
    {
        return [
            self::ContextRead->value,
            self::AccountsRead->value,
            self::PostsRead->value,
            self::AnalyticsRead->value,
            self::OperationsRead->value,
        ];
    }

    /** @return list<string> */
    public static function implemented(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function enabled(): array
    {
        $abilities = self::readOnly();

        if (config('services.zernio.publish_enabled')) {
            $abilities[] = self::PostsPublish->value;
        }

        if (config('services.zernio.schedule_enabled')) {
            $abilities[] = self::PostsSchedule->value;
        }

        return $abilities;
    }

    /** @param list<string> $abilities */
    public static function validate(array $abilities): array
    {
        $allowed = array_column(self::cases(), 'value');

        foreach ($abilities as $ability) {
            if (! in_array($ability, $allowed, true)) {
                throw new \InvalidArgumentException("Unknown agent ability [{$ability}].");
            }
        }

        return array_values(array_unique($abilities));
    }
}
