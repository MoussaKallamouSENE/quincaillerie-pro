<?php

namespace App\Services;

use App\Models\Refund;
use App\Models\RefundDocument;
use App\Models\User;

class RefundAccessService
{
    public function canViewAll(User $user): bool
    {
        return $user->hasAnyRole(['Admin', 'Gérant']);
    }

    public function canView(User $user, Refund $refund): bool
    {
        return $this->canViewAll($user)
            || (int) $refund->requested_by_id === (int) $user->id
            || (int) $refund->executed_by_id === (int) $user->id;
    }

    public function canExecute(User $user, Refund $refund): bool
    {
        return $this->canView($user, $refund)
            && $user->hasPermissionTo('executer_remboursement');
    }

    public function canReconcile(User $user, Refund $refund): bool
    {
        return $this->canView($user, $refund)
            && $user->hasPermissionTo('reconcilier_remboursement');
    }

    public function canUpload(User $user, Refund $refund): bool
    {
        if (! $this->canView($user, $refund)) {
            return false;
        }

        if ($this->canViewAll($user)) {
            return true;
        }

        if ((int) $refund->requested_by_id === (int) $user->id
            && $user->hasPermissionTo('demander_remboursement')) {
            return true;
        }

        return (int) $refund->executed_by_id === (int) $user->id
            && $user->hasPermissionTo('executer_remboursement');
    }

    public function canDelete(User $user, Refund $refund, RefundDocument $document): bool
    {
        if ((int) $document->refund_id !== (int) $refund->id) {
            return false;
        }

        return $this->canView($user, $refund)
            && ($this->canViewAll($user) || (int) $document->uploaded_by_id === (int) $user->id);
    }
}
