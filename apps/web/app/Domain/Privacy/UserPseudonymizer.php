<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Audit\Auditor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class UserPseudonymizer
{
    public const array REFERENCES = [
        'vendors' => ['created_by'],
        'vendor_domains' => ['created_by'],
        'vendor_contacts' => ['created_by', 'verified_by'],
        'vendor_bank_accounts' => ['created_by', 'verified_by'],
        'blocked_bank_accounts' => ['created_by', 'unblocked_by'],
        'payment_requests' => ['requested_by', 'submitted_by', 'decided_by', 'paid_by'],
        'payment_request_files' => ['uploaded_by'],
        'approval_rules' => ['created_by', 'updated_by'],
        'member_limits' => ['updated_by'],
        'approval_routes' => ['created_by'],
        'approvals' => ['approver_id'],
        'verifications' => ['performed_by'],
        'evidence_entries' => ['actor_user_id'],
        'exchange_rates' => ['created_by'],
        'report_exports' => ['requested_by'],
        'sp_notifications' => ['recipient_user_id'],
    ];

    public function __construct(private Auditor $auditor) {}

    public function isReferenced(User $user): bool
    {
        foreach (self::REFERENCES as $table => $columns) {
            $query = DB::table($table)->where(function ($inner) use ($columns, $user): void {
                foreach ($columns as $column) {
                    $inner->orWhere($column, $user->id);
                }
            });

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    public function pseudonymize(User $user): void
    {
        DB::table('passkeys')->where('user_id', $user->id)->delete();
        DB::table('telegram_accounts')->where('user_id', $user->id)->delete();
        DB::table('workspace_user')->where('user_id', $user->id)->delete();
        DB::table('vendors')->where('linked_user_id', $user->id)->update(['linked_user_id' => null]);

        $user->forceFill([
            'name' => self::displayName($user->id, 'en'),
            'email' => 'deleted+'.$user->id.'@invalid',
            'password' => Str::random(64),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => null,
            'current_workspace_id' => null,
            'deleted_at' => now(),
        ])->save();

        $this->auditor->record('auth.account_pseudonymized', $user, actor: null, workspace: null, subjectLabel: '#'.$user->id, platform: true);
    }

    public static function displayName(int $id, string $locale): string
    {
        return (string) __('domain.former_member', ['id' => $id], $locale);
    }
}
