<?php

declare(strict_types=1);

namespace App\Domain\Risk\Sender;

use App\Domain\Intake\Enums\AuthResult;
use App\Domain\Risk\SignalHit;
use App\Domain\Support\EditDistance;
use App\Domain\Support\NameSimilarity;
use App\Domain\Support\Normalize\DomainNormalizer;
use App\Domain\Support\Normalize\EmailNormalizer;

final class SenderAnalyzer
{
    public const string NOTE_IMPERSONATION_SKIPPED = 'display_name_check_skipped_no_own_domains';

    public const string NOTE_INTEL_PLAN = 'domain_intel_not_on_plan';

    private const array AFFIXES = ['billing', 'invoice', 'invoices', 'pay', 'payments', 'accounts', 'finance', 'ar', 'corp', 'inc', 'group', 'co'];

    private const array MULTI_HOMOGLYPHS = ['rn' => 'm', 'vv' => 'w', 'cl' => 'd'];

    private const array CYRILLIC_HOMOGLYPHS = [
        'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x', 'і' => 'i', 'ј' => 'j',
    ];

    private const array SINGLE_HOMOGLYPHS = ['0' => 'o', '1' => 'l', 'i' => 'l'];

    public static function analyze(SenderInput $input): SenderAnalysis
    {
        $fromEmail = EmailNormalizer::normalize($input->fromEmail);
        $replyTo = EmailNormalizer::normalize($input->replyTo);
        $fromDomain = DomainNormalizer::fromEmail($fromEmail);
        $replyDomain = DomainNormalizer::fromEmail($replyTo);
        $returnDomain = $input->returnPath === null
            ? null
            : (str_contains($input->returnPath, '@') ? EmailNormalizer::domain($input->returnPath) : DomainNormalizer::registrable($input->returnPath));

        $roles = [];
        self::addRole($roles, $fromDomain, 'from');
        self::addRole($roles, $replyDomain, 'reply_to');
        self::addRole($roles, $returnDomain, 'return_path');

        foreach ($input->textEmails as $email) {
            self::addRole($roles, EmailNormalizer::domain($email), 'link');
        }

        foreach ($input->urls as $url) {
            self::addRole($roles, DomainNormalizer::fromUrl($url), 'link');
        }

        foreach ($input->textDomains as $domain) {
            self::addRole($roles, DomainNormalizer::registrable($domain), 'link');
        }

        $known = array_values(array_unique(array_filter(array_map(
            static fn (string $domain): ?string => DomainNormalizer::registrable($domain),
            [...($input->vendorMatched ? $input->vendorDomains : $input->workspaceVendorDomains), ...$input->ownDomains],
        ))));
        $vendorDomains = array_values(array_filter(array_map(DomainNormalizer::registrable(...), $input->vendorDomains)));

        $hits = [];
        $notes = [];
        $rows = [];
        $lookalikes = [];
        $punycode = [];

        foreach ($roles as $domain => $role) {
            $freeMail = in_array($domain, $input->freeMailDomains, true);
            $lookalike = $freeMail || in_array($domain, $known, true) ? null : self::findLookalike($domain, $known);
            $facts = $input->facts[$domain] ?? null;
            $registeredAt = $facts?->registeredAt;

            if ($lookalike !== null) {
                $lookalikes[] = ['domain' => $domain, 'resembles' => $lookalike['resembles'], 'technique' => $lookalike['technique']];
            }

            if (str_contains($domain, 'xn--')) {
                $punycode[] = $domain;
            }

            $rows[] = [
                'domain' => $domain,
                'unicode' => DomainNormalizer::unicode($domain),
                'role' => $role,
                'known_for_vendor' => in_array($domain, $vendorDomains, true),
                'lookalike_of' => $lookalike['resembles'] ?? null,
                'technique' => $lookalike['technique'] ?? null,
                'registered_at' => $registeredAt?->toDateString(),
                'age_days' => $registeredAt !== null ? (int) floor($registeredAt->diffInDays($input->today, true)) : null,
                'dmarc_policy' => $facts?->dmarcPolicy,
                'free_mail' => $freeMail,
            ];
        }

        if ($lookalikes !== []) {
            $first = $lookalikes[0];
            $hits[] = new SignalHit('SENDER_DOMAIN_LOOKALIKE', [
                'domain' => $first['domain'],
                'resembles' => $first['resembles'],
                'technique' => $first['technique'],
                'domains' => array_values(array_unique(array_column($lookalikes, 'domain'))),
            ]);
        }

        $fromIsLookalike = $fromDomain !== null && in_array($fromDomain, array_column($lookalikes, 'domain'), true);

        if ($input->vendorMatched && $vendorDomains !== [] && $fromDomain !== null
            && ! in_array($fromDomain, $vendorDomains, true) && ! $fromIsLookalike) {
            $hits[] = new SignalHit('SENDER_DOMAIN_UNKNOWN_FOR_VENDOR', ['domain' => $fromDomain, 'expected' => array_slice($vendorDomains, 0, 3)]);
        }

        if ($replyDomain !== null && $fromDomain !== null && $replyDomain !== $fromDomain) {
            $hits[] = new SignalHit('REPLY_TO_MISMATCH', ['from_domain' => $fromDomain, 'reply_to_domain' => $replyDomain]);
        }

        $freeFrom = $fromDomain !== null && in_array($fromDomain, $input->freeMailDomains, true);
        $freeReply = $replyDomain !== null && in_array($replyDomain, $input->freeMailDomains, true);

        if (($freeFrom || $freeReply) && (! $input->vendorIsEmployee || $input->payrollChange)) {
            $hits[] = new SignalHit('FREE_EMAIL_PROVIDER', ['domain' => $freeFrom ? $fromDomain : $replyDomain]);
        }

        $ownDomains = array_values(array_filter(array_map(DomainNormalizer::registrable(...), $input->ownDomains)));

        if ($ownDomains === []) {
            if ($input->fromName !== null && $fromDomain !== null) {
                $notes[] = self::NOTE_IMPERSONATION_SKIPPED;
            }
        } elseif ($input->fromName !== null && $fromDomain !== null && ! in_array($fromDomain, $ownDomains, true)) {
            $score = NameSimilarity::best($input->fromName, $input->privilegedMemberNames);

            if ($score >= 85) {
                $hits[] = new SignalHit('DISPLAY_NAME_IMPERSONATION', [
                    'display_name' => mb_substr($input->fromName, 0, 60, 'UTF-8'),
                    'domain' => $fromDomain,
                    'similarity' => $score,
                ]);
            }
        }

        if ($input->dmarc === AuthResult::Fail
            || (in_array($input->spf, [AuthResult::Fail, AuthResult::Softfail], true) && $input->dkim !== AuthResult::Pass)) {
            $hits[] = new SignalHit('EMAIL_AUTH_FAILED', [
                'spf' => $input->spf?->value,
                'dkim' => $input->dkim?->value,
                'dmarc' => $input->dmarc?->value,
            ]);
        }

        if ($punycode !== []) {
            $hits[] = new SignalHit('PUNYCODE_DOMAIN', ['domains' => $punycode]);
        }

        if ($fromDomain !== null && ! $freeFrom) {
            $facts = $input->facts[$fromDomain] ?? null;

            if ($facts === null || ! $facts->usable()) {
                $hits[] = new SignalHit('DOMAIN_INTEL_UNAVAILABLE', ['domain' => $fromDomain]);

                if ($input->intelSkippedByPlan) {
                    $notes[] = self::NOTE_INTEL_PLAN;
                }
            } else {
                if ($facts->registeredAt !== null) {
                    $age = (int) floor($facts->registeredAt->diffInDays($input->today, false));

                    if ($age <= (int) config('scampause.risk.domain_recent_days', 30)) {
                        $hits[] = new SignalHit('DOMAIN_RECENTLY_REGISTERED', ['domain' => $fromDomain, 'age_days' => max(0, $age)]);
                    } elseif ($age <= (int) config('scampause.risk.domain_young_days', 180)) {
                        $hits[] = new SignalHit('DOMAIN_YOUNG', ['domain' => $fromDomain, 'age_days' => $age]);
                    }
                }

                if ($facts->dmarcPolicy === 'absent') {
                    $hits[] = new SignalHit('DOMAIN_NO_DMARC', ['domain' => $fromDomain]);
                }
            }
        }

        return new SenderAnalysis($rows, $hits, array_values(array_unique($notes)), $fromDomain, $replyDomain);
    }

    /**
     * @param  list<string>  $known
     * @return array{resembles: string, technique: string}|null
     */
    public static function findLookalike(string $domain, array $known): ?array
    {
        $candidate = DomainNormalizer::split($domain);

        if ($candidate === null) {
            return null;
        }

        foreach ($known as $knownDomain) {
            $reference = DomainNormalizer::split($knownDomain);

            if ($reference === null || $reference['registrable'] === $candidate['registrable']) {
                continue;
            }

            $technique = self::technique($candidate, $reference);

            if ($technique !== null) {
                return ['resembles' => $reference['registrable'], 'technique' => $technique];
            }
        }

        return null;
    }

    /**
     * @param  array{registrable: string, label: string, suffix: string}  $candidate
     * @param  array{registrable: string, label: string, suffix: string}  $reference
     */
    public static function technique(array $candidate, array $reference): ?string
    {
        $ld = $candidate['label'];
        $lk = $reference['label'];

        if ($ld === $lk) {
            return $candidate['suffix'] !== $reference['suffix'] ? 'tld_swap' : null;
        }

        $decoded = str_starts_with($ld, 'xn--') ? (DomainNormalizer::unicode($ld) ?? $ld) : $ld;

        if (self::skeleton($decoded) === self::skeleton($lk)) {
            return 'homoglyph';
        }

        $affixes = implode('|', self::AFFIXES);
        $quoted = preg_quote($lk, '/');

        if (preg_match('/^(?:'.$quoted.'-?(?:'.$affixes.')|(?:'.$affixes.')-?'.$quoted.')$/u', $decoded) === 1) {
            return 'affix';
        }

        $length = mb_strlen($lk, 'UTF-8');
        $distance = EditDistance::damerauLevenshtein($decoded, $lk);

        if (($length >= 8 && $distance <= 2) || ($length >= 4 && $length < 8 && $distance <= 1)) {
            return 'edit_distance';
        }

        return null;
    }

    public static function skeleton(string $label): string
    {
        $value = mb_strtolower($label, 'UTF-8');
        $value = strtr($value, self::CYRILLIC_HOMOGLYPHS);
        $value = strtr($value, self::MULTI_HOMOGLYPHS);

        return strtr($value, self::SINGLE_HOMOGLYPHS);
    }

    /**
     * @param  array<string, string>  $roles
     */
    private static function addRole(array &$roles, ?string $domain, string $role): void
    {
        if ($domain !== null && ! array_key_exists($domain, $roles)) {
            $roles[$domain] = $role;
        }
    }
}
