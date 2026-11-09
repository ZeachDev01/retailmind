<?php

namespace App\Backup\Compatibility;

/** A declared transformation from one known older structure to the destination. */
interface SchemaAdapter
{
    public static function key(): string;

    public static function label(): string;

    /**
     * @param array<string,array{ddl:string,columns:list<string>}> $source
     * @param list<string> $appliedMigrations migration keys recorded in the package
     */
    public function supports(array $source, array $appliedMigrations): bool;

    /**
     * Source-side changes this adapter can explain; destination-only additions
     * are safe (defaults apply) and are not declared.
     *
     * @return array{tables_removed:list<string>,columns_removed:list<string>,changed:array<string,list<string>>,enums:array<string,array>}
     */
    public function declaredChanges(): array;

    /**
     * @param array<string,list<array<string,?string>>> $rows
     * @param array<string,array{ddl:string,columns:list<string>}> $source
     * @param array<string,array{ddl:string,columns:list<string>}> $destination
     * @return array<string,list<array<string,?string>>>
     */
    public function upgrade(array $rows, array $source, array $destination): array;
}
