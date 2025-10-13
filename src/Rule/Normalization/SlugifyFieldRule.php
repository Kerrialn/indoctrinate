<?php
declare(strict_types=1);

namespace Indoctrinate\Rule\Normalization;

use Indoctrinate\Log\Log;
use Indoctrinate\Rule\Contract\RuleInterface;
use PDO;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

final class SlugifyFieldRule implements RuleInterface
{
    public static function getName(): string { return 'slugify_field'; }

    public static function getDescription(): string
    {
        return 'creates a slug column from a source column, and ensures it is unique';
    }
    public static function getCategory(): string { return 'Normalization'; }
    public static function isDestructive(): bool { return false; }

    /**
     * Context must include:
     *  - table, source_field|sourceField, target_field|targetField
     * Optional:
     *  - target_length (int, default 191)
     *  - lowercase (bool, default true)
     *  - separator (string, default '-')
     *  - overwrite_existing (bool, default false)
     *  - create_index (bool, default true)
     *  - unique (bool, default true)
     *  - batch_size (int, default 1000)
     *  - dry (bool, default false)  <-- ONLY THIS FLAG
     */
    public function apply(PDO $pdo, OutputInterface $output, array $context = []): array
    {
        $ctx = $this->normalizeContext($context);

        $table       = $ctx['table'];
        $src         = $ctx['sourceField'];
        $dst         = $ctx['targetField'];
        $len         = $ctx['targetLength'];
        $lower       = $ctx['lowercase'];
        $sep         = $ctx['separator'];
        $overwrite   = $ctx['overwriteExisting'];
        $createIndex = $ctx['createIndex'];
        $unique      = $ctx['unique'];
        $batchSize   = $ctx['batchSize'];
        $dry         = (bool)$ctx['dry']; // <-- single flag

        $results = [];

        // validate source exists
        $this->assertColumnExists($pdo, $table, $src);

        // WRITE #1: add column (guarded)
        if (!$this->columnExists($pdo, $table, $dst)) {
            if ($dry) {
                $output->writeln(sprintf('[%s] DRY: WOULD add `%s`.`%s` VARCHAR(%d)', self::getName(), $table, $dst, $len));
                $results[] = new Log(self::getName(), $table, $dst, "DRY: would add column VARCHAR($len)", 'SKIPPED');
            } else {
                $pdo->exec(sprintf(
                    "ALTER TABLE `%s` ADD COLUMN `%s` VARCHAR(%d) NULL",
                    $this->qt($table), $this->qt($dst), $len
                ));
                $output->writeln(sprintf('[%s] added `%s`.`%s` VARCHAR(%d)', self::getName(), $table, $dst, $len));
                $results[] = new Log(self::getName(), $table, $dst, "added column VARCHAR($len)", 'OK');
            }
        }

        // single-column PK required
        $pk = $this->detectSingleColumnPrimaryKey($pdo, $table)
            ?? throw new RuntimeException("Table `$table` must have a single-column PRIMARY KEY.");

        // WRITE #2: index creation (guarded)
        if ($createIndex) {
            $haveUnique = $this->hasIndex($pdo, $table, $dst, true);
            $haveAny    = $haveUnique || $this->hasIndex($pdo, $table, $dst, false);

            if ($unique && !$haveUnique) {
                if ($dry) {
                    $output->writeln(sprintf('[%s] DRY: WOULD create UNIQUE index on `%s`.`%s`', self::getName(), $table, $dst));
                    $results[] = new Log(self::getName(), $table, $dst, 'DRY: would create UNIQUE index', 'SKIPPED');
                } else {
                    $this->ensureIndex($pdo, $table, $dst, true, $results, $output);
                }
            } elseif (!$unique && !$haveAny) {
                if ($dry) {
                    $output->writeln(sprintf('[%s] DRY: WOULD create index on `%s`.`%s`', self::getName(), $table, $dst));
                    $results[] = new Log(self::getName(), $table, $dst, 'DRY: would create NON-UNIQUE index', 'SKIPPED');
                } else {
                    $this->ensureIndex($pdo, $table, $dst, false, $results, $output);
                }
            }
        }

        // WHERE for updates
        $where = $overwrite
            ? '1=1'
            : sprintf("(`%s` IS NULL OR `%s` = '')", $this->qt($dst), $this->qt($dst));

        // Count candidates
        $toProcess = (int)$pdo->query(sprintf(
            "SELECT COUNT(*) FROM `%s` WHERE %s",
            $this->qt($table), $where
        ))->fetchColumn();

        if ($dry) {
            $output->writeln(sprintf('[%s] DRY: %d rows would be (re)slugified', self::getName(), $toProcess));
            $results[] = new Log(self::getName(), $table, $dst, "DRY: would process=$toProcess", 'SKIPPED');
            return $results; // EARLY RETURN ON DRY
        }

        $output->writeln(sprintf('[%s] %d rows to (re)slugify', self::getName(), $toProcess));

        $existing = $unique ? $this->loadExistingSlugSet($pdo, $table, $dst) : [];

        $select = $pdo->prepare(sprintf(
            "SELECT `%s` AS id, `%s` AS src
               FROM `%s`
              WHERE %s
              ORDER BY `%s` ASC
              LIMIT :limit OFFSET :offset",
            $this->qt($pk), $this->qt($src),
            $this->qt($table), $where, $this->qt($pk)
        ));
        $update = $pdo->prepare(sprintf(
            "UPDATE `%s` SET `%s` = :slug WHERE `%s` = :id",
            $this->qt($table), $this->qt($dst), $this->qt($pk)
        ));

        // Batch update
        $processed = 0;
        for ($offset = 0; $offset < $toProcess; $offset += $batchSize) {
            $select->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $select->bindValue(':offset', $offset, PDO::PARAM_INT);
            $select->execute();

            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;

            foreach ($rows as $r) {
                $id  = $r['id'];
                $val = (string)($r['src'] ?? '');

                $slug = $this->slugify($val, $sep, $lower);
                $slug = $slug === '' ? null : $this->trimToLength($slug, $len);

                if ($slug !== null && $unique) {
                    $slug = $this->ensureUniqueSlug($pdo, $table, $dst, $slug, $len, $existing);
                }

                $update->execute([':slug' => $slug, ':id' => $id]);
                $processed++;
            }

            $output->writeln(sprintf('[%s] %d/%d', self::getName(), min($processed, $toProcess), $toProcess));
        }

        // WRITE #3: retry unique index if needed (not dry)
        if ($createIndex && $unique && !$this->hasIndex($pdo, $table, $dst, true)) {
            $this->ensureIndex($pdo, $table, $dst, true, $results, $output);
        }

        $results[] = new Log(self::getName(), $table, $dst, "processed=$processed", 'OK');
        return $results;
    }

    /* ---------------- helpers ---------------- */

    private function normalizeContext(array $ctx): array
    {
        // map incoming keys to our internal names
        $map = [
            'table'              => 'table',
            'source_field'       => 'sourceField',
            'sourceField'        => 'sourceField',
            'target_field'       => 'targetField',
            'targetField'        => 'targetField',
            'target_length'      => 'targetLength',
            'targetLength'       => 'targetLength',
            'lowercase'          => 'lowercase',
            'separator'          => 'separator',
            'overwrite_existing' => 'overwriteExisting',
            'overwriteExisting'  => 'overwriteExisting',
            'create_index'       => 'createIndex',
            'createIndex'        => 'createIndex',
            'unique'             => 'unique',
            'batch_size'         => 'batchSize',
            'batchSize'          => 'batchSize',
            // ONLY accept 'dry'
            'dry'                => 'dry',
        ];

        $out = [];
        foreach ($map as $in => $outKey) {
            if (array_key_exists($in, $ctx)) {
                $out[$outKey] = $ctx[$in];
            }
        }

        // defaults
        $out += [
            'targetLength'      => 191,
            'lowercase'         => true,
            'separator'         => '-',
            'overwriteExisting' => false,
            'createIndex'       => true,
            'unique'            => true,
            'batchSize'         => 1000,
            'dry'               => false, // single dry flag
        ];

        foreach (['table','sourceField','targetField'] as $req) {
            if (!isset($out[$req]) || $out[$req] === '') {
                throw new RuntimeException("Missing required context key: $req");
            }
        }

        return $out;
    }

    private function slugify(string $str, string $sep, bool $lower): string
    {
        $s = $str;

        if (\class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII; [:Nonspacing Mark:] Remove; [:Punctuation:] Remove;');
            if ($t) $s = $t->transliterate($s) ?? $s;
        } elseif (\function_exists('iconv')) {
            $i = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($i !== false) $s = $i;
        }

        $s = preg_replace('~[^\\pL\\pN]+~u', $sep, $s) ?? '';
        $s = trim($s, $sep);
        $s = preg_replace('~[^-a-zA-Z0-9_]+~', '', $s) ?? '';
        if ($lower) $s = strtolower($s);
        $s = preg_replace('~-+~', '-', $s) ?? '';
        return $s;
    }

    private function trimToLength(string $slug, int $len): string
    {
        return mb_strimwidth($slug, 0, $len, '');
    }

    private function ensureUniqueSlug(PDO $pdo, string $table, string $col, string $slug, int $len, array &$existing): string
    {
        if (!isset($existing[$slug]) && !$this->slugExists($pdo, $table, $col, $slug)) {
            $existing[$slug] = true;
            return $slug;
        }
        $base = $slug;
        for ($n = 2; ; $n++) {
            $suffix = "-$n";
            $try = $this->trimToLength($base, $len - strlen($suffix)) . $suffix;
            if (!isset($existing[$try]) && !$this->slugExists($pdo, $table, $col, $try)) {
                $existing[$try] = true;
                return $try;
            }
        }
    }

    private function slugExists(PDO $pdo, string $table, string $col, string $slug): bool
    {
        $st = $pdo->prepare(sprintf(
            "SELECT 1 FROM `%s` WHERE `%s` = :s LIMIT 1",
            $this->qt($table), $this->qt($col)
        ));
        $st->execute([':s' => $slug]);
        return (bool)$st->fetchColumn();
    }

    private function loadExistingSlugSet(PDO $pdo, string $table, string $col): array
    {
        $rows = $pdo->query(sprintf(
            "SELECT `%s` FROM `%s` WHERE `%s` IS NOT NULL AND `%s` <> ''",
            $this->qt($col), $this->qt($table), $this->qt($col), $this->qt($col)
        ))->fetchAll(PDO::FETCH_COLUMN, 0);

        $set = [];
        foreach ($rows as $s) {
            $set[(string)$s] = true;
        }
        return $set;
    }

    private function detectSingleColumnPrimaryKey(PDO $pdo, string $table): ?string
    {
        $st = $pdo->prepare(
            "SELECT COLUMN_NAME
               FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :t
                AND CONSTRAINT_NAME = 'PRIMARY'
              ORDER BY ORDINAL_POSITION"
        );
        $st->execute([':t' => $table]);
        $cols = $st->fetchAll(PDO::FETCH_COLUMN, 0);
        return \count($cols) === 1 ? (string)$cols[0] : null;
    }

    private function hasIndex(PDO $pdo, string $table, string $col, bool $unique): bool
    {
        $rows = $pdo->query(sprintf("SHOW INDEX FROM `%s`", $this->qt($table)))->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (strcasecmp((string)($r['Column_name'] ?? ''), $col) === 0) {
                if ($unique) {
                    if ((int)($r['Non_unique'] ?? 1) === 0) return true;
                } else {
                    return true;
                }
            }
        }
        return false;
    }

    private function ensureIndex(PDO $pdo, string $table, string $col, bool $unique, array &$results, OutputInterface $out): void
    {
        $idxName = sprintf('%s_%s_%s_idx', $unique ? 'uniq' : 'idx', $table, $col);
        $sql = sprintf(
            "ALTER TABLE `%s` ADD %s `%s` (`%s`)",
            $this->qt($table),
            $unique ? 'UNIQUE INDEX' : 'INDEX',
            $this->qt($idxName),
            $this->qt($col)
        );
        try {
            $pdo->exec($sql);
            $results[] = new Log(self::getName(), $table, $col, ($unique ? 'unique ' : '') . 'index created', 'OK');
            $out->writeln(sprintf('[%s] %s index `%s` created on `%s`.`%s`',
                self::getName(), $unique ? 'UNIQUE' : 'NON-UNIQUE', $idxName, $table, $col
            ));
        } catch (\Throwable $e) {
            $results[] = new Log(self::getName(), $table, $col, 'index creation failed', $e->getMessage());
            $out->writeln(sprintf('[%s] index creation failed: %s', self::getName(), $e->getMessage()));
        }
    }

    private function columnExists(PDO $pdo, string $table, string $col): bool
    {
        $st = $pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :t
                AND COLUMN_NAME = :c"
        );
        $st->execute([':t' => $table, ':c' => $col]);
        return (bool)$st->fetchColumn();
    }

    private function assertColumnExists(PDO $pdo, string $table, string $col): void
    {
        if (!$this->columnExists($pdo, $table, $col)) {
            throw new RuntimeException("Column `$table`.`$col` not found.");
        }
    }

    private function qt(string $ident): string
    {
        return str_replace('`', '``', $ident);
    }
}