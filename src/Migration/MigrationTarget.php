<?php

namespace Daikon\Dbal\Migration;

use Assert\Assertion;
use Daikon\Dbal\Exception\MigrationException;

final class MigrationTarget implements MigrationTargetInterface
{
    private $name;

    private $enabled;

    private $migrationAdapter;

    private $migrationLoader;

    private $migrationList;

    public function __construct(
        string $name,
        bool $enabled,
        MigrationAdapterInterface $migrationAdapter,
        MigrationLoaderInterface $migrationLoader
    ) {
        $this->name = $name;
        $this->enabled = $enabled;
        $this->migrationAdapter = $migrationAdapter;
        $this->migrationLoader = $migrationLoader;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isEnabled(): bool
    {
        return $this->enabled === true;
    }

    public function getMigrationList(): MigrationList
    {
        if (!$this->migrationList) {
            $availableMigrations = $this->migrationLoader->load();
            $executedMigrations = $this->migrationAdapter->read($this->name);
            $pendingMigrations = $availableMigrations->diff($executedMigrations);
            $this->migrationList = $executedMigrations->merge($pendingMigrations);
        }
        return $this->migrationList;
    }

    public function migrate(string $direction, string $version = null): MigrationList
    {
        Assertion::true($this->isEnabled());

        if ($direction === MigrationInterface::MIGRATE_DOWN) {
            $executedMigrations = $this->migrateDown($version);
        } else {
            $executedMigrations = $this->migrateUp($version);
        }

        return $executedMigrations;
    }

    private function migrateUp(int $version = null): MigrationList
    {
        $migrationList = $this->getMigrationList();
        $pendingMigrations = $migrationList->getPendingMigrations();
        $executedMigrations = $migrationList->getExecutedMigrations();

        if ($version && !$pendingMigrations->containsVersion($version)) {
            throw new MigrationException(sprintf('Target version %s not found in pending migrations.', $version));
        }

        $completedMigrations = [];
        $connector = $this->migrationAdapter->getConnector();
        foreach ($pendingMigrations as $migration) {
            $migration->execute($connector, MigrationInterface::MIGRATE_UP);
            $executedMigrations = $executedMigrations->push($migration);
            $this->migrationAdapter->write($this->name, $executedMigrations);
            $completedMigrations[] = $migration;
            if ($version && $migration->getVersion() === $version) {
                break;
            }
        }

        return new MigrationList($completedMigrations);
    }

    private function migrateDown(int $version = null): MigrationList
    {
        $migrationList = $this->getMigrationList();
        $executedMigrations = $migrationList->getExecutedMigrations();

        if ($version && !$executedMigrations->containsVersion($version)) {
            throw new MigrationException(sprintf('Target version %s not found in executed migrations.', $version));
        }

        $completedMigrations = [];
        $connector = $this->migrationAdapter->getConnector();
        foreach ($executedMigrations->reverse() as $migration) {
            if ($version && $migration->getVersion() === $version) {
                break;
            }
            $migration->execute($connector, MigrationInterface::MIGRATE_DOWN);
            $executedMigrations = $executedMigrations->remove($migration);
            if ($executedMigrations->count() > 0) {
                /*
                 * Do not write version info after reversing initial migration since we have
                 * an assumption that the database was deleted!
                 */
                $this->migrationAdapter->write($this->name, $executedMigrations);
            }
            $completedMigrations[] = $migration;
        }

        return new MigrationList($completedMigrations);
    }
}