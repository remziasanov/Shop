<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Action codes of the admin journal (RB-12). Customer and wholesale codes are added by the stages that need them.
 */
enum AuditAction: string
{
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RolePermissionsChanged = 'role.permissions_changed';
    case RoleDeleted = 'role.deleted';
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserRoleChanged = 'user.role_changed';
    case UserActivated = 'user.activated';
    case UserDeactivated = 'user.deactivated';
    case UserDeleted = 'user.deleted';
    case WholesaleApplicationCreated = 'wholesale.created';
    case WholesaleApplicationApproved = 'wholesale.approved';
    case WholesaleApplicationRejected = 'wholesale.rejected';
    case WholesaleApplicationReturned = 'wholesale.returned';
    case CustomerUpdated = 'customer.updated';
    case CustomerGroupChanged = 'customer.group_changed';
    case CustomerBlocked = 'customer.blocked';
    case CustomerUnblocked = 'customer.unblocked';
    case CustomerAnonymized = 'customer.anonymized';
    case CustomerCodeResent = 'customer.code_resent';
    case ThemeChanged = 'theme.changed';
    case ThemeColorsChanged = 'theme.colors_changed';
    case ThemeFontsChanged = 'theme.fonts_changed';
    case UserTelegramLinked = 'user.telegram_linked';
    case UserTelegramUnlinked = 'user.telegram_unlinked';
    case OrderStatusChanged = 'order.status_changed';
    case OrderReservationExtended = 'order.reservation_extended';
    case OrderManagerCommentChanged = 'order.manager_comment_changed';

    public function label(): string
    {
        // The codes contain dots, so `__('…actions.role.created')` would look for nested arrays: read the flat map itself.
        $lines = trans('roles.audit.actions');

        return is_array($lines) && is_string($lines[$this->value] ?? null) ? $lines[$this->value] : $this->value;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
