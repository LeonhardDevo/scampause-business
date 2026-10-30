<?php

declare(strict_types=1);

namespace App\Domain\Support\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

final class DomainSchema
{
    public static function workspace(Blueprint $table): void
    {
        $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
    }

    public static function identity(Blueprint $table): void
    {
        $table->id()->generatedAs();
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    public static function enumCheck(string $table, string $column, string $enum, bool $nullable = false): void
    {
        $values = array_map(static fn (\BackedEnum $case): string => "'".$case->value."'", $enum::cases());
        $expression = $column.' IN ('.implode(', ', $values).')';

        if ($nullable) {
            $expression = $column.' IS NULL OR '.$expression;
        }

        self::check($table, $table.'_'.$column.'_check', $expression);
    }

    /**
     * @param  list<string>  $values
     */
    public static function valuesCheck(string $table, string $column, array $values, bool $nullable = false): void
    {
        $quoted = array_map(static fn (string $value): string => "'".$value."'", $values);
        $expression = $column.' IN ('.implode(', ', $quoted).')';

        if ($nullable) {
            $expression = $column.' IS NULL OR '.$expression;
        }

        self::check($table, $table.'_'.$column.'_check', $expression);
    }

    public static function check(string $table, string $name, string $expression): void
    {
        DB::statement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', $table, $name, $expression));
    }

    public static function immutableTrigger(string $table, string $function): void
    {
        DB::statement(sprintf(
            'CREATE TRIGGER %s_immutable BEFORE UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION %s()',
            $table,
            $table,
            $function,
        ));
    }
}
