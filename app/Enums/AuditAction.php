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

    public function label(): string
    {
        return __('roles.audit.actions.'.$this->value);
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
