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
    public static function clientTokenDefaults(): array
    {
        return [
            ...self::readOnly(),
            self::PostsPublish->value,
        ];
    }
}
