<?php

declare(strict_types=1);

namespace App\State;

use App\Config;

/**
 * MariaDB-backed state: last processed build id / docs hash per branch, plus a
 * run history. The orchestrator is the sole owner of this schema.
 */
final class StateStore
{
    private ?\PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    private function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new \PDO(
                (string) $this->config->require('database.dsn'),
                (string) $this->config->require('database.user'),
                (string) $this->config->require('database.password'),
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
        }

        return $this->pdo;
    }

    public function migrate(): void
    {
        $this->pdo()->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS branch_state (
                branch             VARCHAR(64)  NOT NULL PRIMARY KEY,
                last_buildid       VARCHAR(32)  NULL,
                last_docs_hash     CHAR(64)     NULL,
                last_game_version  VARCHAR(32)  NULL,
                updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        // Add the column for DBs created before it existed (MariaDB syntax).
        $this->pdo()->exec('ALTER TABLE branch_state ADD COLUMN IF NOT EXISTS last_game_version VARCHAR(32) NULL');

        // Content-addressed CDN images are global + immutable: an id (hash prefix)
        // present here means the 256/64 files are already published, so we never
        // re-copy or re-upload them.
        $this->pdo()->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cdn_image (
                id           VARCHAR(32)  NOT NULL PRIMARY KEY,
                sha256       CHAR(64)     NOT NULL,
                first_build  VARCHAR(32)  NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $this->pdo()->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS run (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                branch        VARCHAR(64)  NOT NULL,
                buildid       VARCHAR(32)  NULL,
                game_version  VARCHAR(32)  NULL,
                status        VARCHAR(32)  NOT NULL,
                notes         TEXT         NULL,
                started_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at   DATETIME     NULL,
                KEY branch_started (branch, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $this->pdo()->exec('ALTER TABLE run ADD COLUMN IF NOT EXISTS game_version VARCHAR(32) NULL');
    }

    public function ping(): void
    {
        $this->pdo()->query('SELECT 1');
    }

    public function getLastBuildId(string $branch): ?string
    {
        $stmt = $this->pdo()->prepare('SELECT last_buildid FROM branch_state WHERE branch = ?');
        $stmt->execute([$branch]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function setBuildId(string $branch, string $buildId): void
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            INSERT INTO branch_state (branch, last_buildid)
            VALUES (:branch, :buildid)
            ON DUPLICATE KEY UPDATE last_buildid = VALUES(last_buildid)
        SQL);
        $stmt->execute(['branch' => $branch, 'buildid' => $buildId]);
    }

    public function getLastDocsHash(string $branch): ?string
    {
        $stmt = $this->pdo()->prepare('SELECT last_docs_hash FROM branch_state WHERE branch = ?');
        $stmt->execute([$branch]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    public function setDocsHash(string $branch, string $hash): void
    {
        $stmt = $this->pdo()->prepare(<<<'SQL'
            INSERT INTO branch_state (branch, last_docs_hash)
            VALUES (:branch, :hash)
            ON DUPLICATE KEY UPDATE last_docs_hash = VALUES(last_docs_hash)
        SQL);
        $stmt->execute(['branch' => $branch, 'hash' => $hash]);
    }

    /**
     * @param list<string> $ids
     * @return array<string,string> id => stored sha256, for the subset already present
     */
    public function getExistingImageIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, \count($ids), '?'));
        $stmt = $this->pdo()->prepare("SELECT id, sha256 FROM cdn_image WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($ids));

        $existing = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_KEY_PAIR) as $id => $sha) {
            $existing[(string) $id] = (string) $sha;
        }

        return $existing;
    }

    /**
     * @param list<array{id:string,sha256:string,build:?string}> $rows
     */
    public function recordImages(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $stmt = $this->pdo()->prepare(<<<'SQL'
            INSERT INTO cdn_image (id, sha256, first_build) VALUES (:id, :sha256, :build)
            ON DUPLICATE KEY UPDATE id = id
        SQL);
        foreach ($rows as $row) {
            $stmt->execute(['id' => $row['id'], 'sha256' => $row['sha256'], 'build' => $row['build']]);
        }
    }

    public function setGameVersion(string $branch, ?string $version): void
    {
        if ($version === null) {
            return;
        }
        $stmt = $this->pdo()->prepare(<<<'SQL'
            INSERT INTO branch_state (branch, last_game_version)
            VALUES (:branch, :version)
            ON DUPLICATE KEY UPDATE last_game_version = VALUES(last_game_version)
        SQL);
        $stmt->execute(['branch' => $branch, 'version' => $version]);
    }

    public function setRunGameVersion(int $runId, ?string $version): void
    {
        $stmt = $this->pdo()->prepare('UPDATE run SET game_version = ? WHERE id = ?');
        $stmt->execute([$version, $runId]);
    }

    public function startRun(string $branch, ?string $buildId): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO run (branch, buildid, status) VALUES (?, ?, ?)'
        );
        $stmt->execute([$branch, $buildId, 'running']);

        return (int) $this->pdo()->lastInsertId();
    }

    public function finishRun(int $runId, string $status, ?string $notes = null): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE run SET status = ?, notes = ?, finished_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$status, $notes, $runId]);
    }
}
