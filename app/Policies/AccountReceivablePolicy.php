<?php

namespace App\Policies;

use App\Models\User;
use App\Models\AccountReceivable;
use Illuminate\Auth\Access\HandlesAuthorization;

class AccountReceivablePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_account::receivable');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AccountReceivable $accountReceivable): bool
    {
        return $user->can('view_account::receivable');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_account::receivable');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AccountReceivable $accountReceivable): bool
    {
        return $user->can('update_account::receivable');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AccountReceivable $accountReceivable): bool
    {
        return $user->can('delete_account::receivable');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_account::receivable');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, AccountReceivable $accountReceivable): bool
    {
        return $user->can('force_delete_account::receivable');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_account::receivable');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, AccountReceivable $accountReceivable): bool
    {
        return $user->can('restore_account::receivable');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_account::receivable');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, AccountReceivable $accountReceivable): bool
    {
        return $user->can('replicate_account::receivable');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_account::receivable');
    }
}
