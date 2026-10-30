<?php

declare(strict_types=1);

namespace Database\Seeders\Domain;

use App\Models\TelegramAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Domain\Support\WorkspaceSetup;
use Illuminate\Database\Seeder;

final class DemoUsersSeeder extends Seeder
{
    public const array USERS = [
        'admin' => ['email' => 'admin', 'name' => 'Platform Admin', 'locale' => 'en', 'telegram' => null],
        'alex' => ['email' => 'demo', 'name' => 'Alex Morgan', 'locale' => 'en', 'telegram' => 900001],
        'maria' => ['email' => 'maria', 'name' => 'Мария Ковалёва', 'locale' => 'ru', 'telegram' => 900002],
        'sam' => ['email' => 'sam', 'name' => 'Sam Patel', 'locale' => 'en', 'telegram' => 900003],
        'irina' => ['email' => 'irina', 'name' => 'Ирина Соколова', 'locale' => 'ru', 'telegram' => 900004],
        'jordan' => ['email' => 'jordan', 'name' => 'Jordan Lee', 'locale' => 'en', 'telegram' => 900005],
        'pat' => ['email' => 'auditor', 'name' => 'Pat Kim', 'locale' => 'en', 'telegram' => null],
        'olga' => ['email' => 'olga', 'name' => 'Ольга Смирнова', 'locale' => 'ru', 'telegram' => 900006],
    ];

    /** @var array<string, User> */
    public array $users = [];

    public function run(): void
    {
        $this->users = self::ensure(CarbonImmutable::now()->subDays(130));
    }

    /**
     * @return array<string, User>
     */
    public static function ensure(CarbonImmutable $linkedAt): array
    {
        $slug = (string) config('product.slug', 'scampause-business');
        $users = [];

        foreach (self::USERS as $key => $spec) {
            $user = User::query()->firstOrNew(['email' => $spec['email'].'@'.$slug.'.test']);

            if (! $user->exists) {
                $user->forceFill([
                    'name' => $spec['name'],
                    'password' => 'password',
                    'locale' => $spec['locale'],
                    'email_verified_at' => $linkedAt,
                ])->save();
            }

            if ($spec['telegram'] !== null && ! TelegramAccount::query()->where('user_id', $user->id)->exists()) {
                $account = new TelegramAccount;
                $account->forceFill([
                    'user_id' => $user->id,
                    'telegram_user_id' => $spec['telegram'],
                    'chat_id' => $spec['telegram'],
                    'username' => 'demo_'.$key,
                    'language_code' => $spec['locale'],
                    'linked_at' => $linkedAt,
                ])->save();
            }

            $users[$key] = $user->refresh();
        }

        WorkspaceSetup::acceptLegal(array_values($users));

        return $users;
    }
}
