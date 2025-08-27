<?php
// src/Config/DbFixerConfig.php
namespace DbFixer\Config;

use DbFixer\Rule\Contract\RuleConstraintInterface;

final class DbFixerConfig
{
    /** @var string */
    private $driver;
    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var string */
    private $dbname;
    /** @var string */
    private $user;
    /** @var string */
    private $password;
    /** @var string */
    private $charset = 'utf8mb4';

    /**
     * Normalized rule definitions.
     * Each item: ['class' => string, 'constraints' => (RuleConstraints|null)]
     * @var array<int, array{class:string, RuleConstraintInterface|null}>
     */
    private $ruleDefs = [];

    public function connection(
        $driver,
        $host,
        $port,
        $dbname,
        $user,
        $password,
        $charset = 'utf8mb4'
    ): void {
        $this->driver   = (string)$driver;
        $this->host     = (string)$host;
        $this->port     = (int)$port;
        $this->dbname   = (string)$dbname;
        $this->user     = (string)$user;
        $this->password = (string)$password;
        $this->charset  = (string)$charset;
    }

    public function getDsn(): string
    {
        return "{$this->driver}:host={$this->host};port={$this->port};dbname={$this->dbname};charset={$this->charset}";
    }

    public function getUser(): string { return $this->user; }
    public function getPort(): int     { return $this->port; }
    public function getPassword(): string { return $this->password; }

    /**
     * Configure rules.
     *
     * Accepts:
     *  - [FQCN, FQCN, ...]                                  // no options
     *  - [FQCN => RuleConstraints, OtherFQCN => Constraints] // typed options
     *  - [ ['class'=>FQCN,'constraints'=>RuleConstraints], ... ] // explicit
     *
     * @param array $rules
     */
    public function rules(array $rules): void
    {
        $out = [];
        foreach ($rules as $key => $val) {
            if (is_string($val)) {
                // simple class name
                $out[] = ['class' => $val, 'constraints' => null];
                continue;
            }
            if (is_string($key) && ($val instanceof RuleConstraintInterface)) {
                // class => constraints object
                $out[] = ['class' => $key, 'constraints' => $val];
                continue;
            }
            if (is_array($val) && isset($val['class'])) {
                $constraints = isset($val['constraints']) ? $val['constraints'] : null;
                if ($constraints !== null && !($constraints instanceof RuleConstraintInterface)) {
                    throw new \InvalidArgumentException('constraints must implement RuleConstraints');
                }
                $out[] = ['class' => $val['class'], 'constraints' => $constraints];
                continue;
            }
            throw new \InvalidArgumentException('Unsupported rule entry shape in DbFixerConfig::rules()');
        }
        $this->ruleDefs = $out;
    }

    /**
     * New accessor: normalized rule definitions (preferred).
     * @return array<int, array{class:string, RuleConstraintInterface}>
     */
    public function getRuleDefinitions(): array
    {
        return $this->ruleDefs;
    }

    /**
     * Backwards-compat: returns just class names.
     * @return string[]
     */
    public function getRules(): array
    {
        return array_map(function ($def) { return $def['class']; }, $this->ruleDefs);
    }
}
