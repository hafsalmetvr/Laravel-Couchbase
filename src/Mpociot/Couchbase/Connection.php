<?php declare(strict_types=1);

namespace Mpociot\Couchbase;

use CouchbaseBucket;
use \Couchbase\QueryOptions;
use Mpociot\Couchbase\Events\QueryFired;
use Mpociot\Couchbase\Query\Builder as QueryBuilder;
use Mpociot\Couchbase\Query\Grammar as QueryGrammar;

class Connection extends \Illuminate\Database\Connection
{
    const AUTH_TYPE_USER_PASSWORD = 'password';
    const AUTH_TYPE_CLUSTER_ADMIN = 'cluster';
    const AUTH_TYPE_NONE = 'none';

    /**
     * The Couchbase database handler.
     *
     * @var CouchbaseBucket
     */
    protected $bucket;

    /** @var string[] */
    protected $metrics;

    /**
     * The Couchbase connection handler.
     *
     * @var CouchbaseCluster
     */
    public $connection;
    
    
    /*
     * cluster
     */
    public $cluster;

    /**
     * @var string
     */
    protected $bucketname;

    /** @var boolean */
    protected $inlineParameters;

    /**
     * Create a new database connection instance.
     *
     * @param  array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        // Build the connection string
        $dsn = $this->getDsn($config);

        // Create the connection
        $this->connection = $this->createConnection($dsn, $config);
        if (isset($config['username']) && isset($config['password']) && isset($config['auth_type'])) {
            if ($config['auth_type'] === self::AUTH_TYPE_USER_PASSWORD) {
                // Couchbase 5.x
                $cbAuth = new \Couchbase\PasswordAuthenticator();
                $cbAuth->username($config['username']);
                $cbAuth->password($config['password']);
                $this->connection->authenticate($cbAuth);
            } elseif ($config['auth_type'] === self::AUTH_TYPE_CLUSTER_ADMIN) {
                // Couchbase 4.x
                $cbAuth = new \CouchbaseAuthenticator();
                $cbAuth->cluster($config['username'], $config['password']);
                $this->connection->authenticate($cbAuth);
            }
        }

        // Select database
        $this->bucketname = $config['bucket'];
        $this->bucket = $this->connection->bucket($this->bucketname);
        $this->inlineParameters = isset($config['inline_parameters']) ? (bool)$config['inline_parameters'] : false;

        $this->useDefaultQueryGrammar();
        $this->useDefaultPostProcessor();
        $this->useDefaultSchemaGrammar();
    }

    /**
     * @param bool $inlineParameters
     */
    public function setInlineParameters(bool $inlineParameters)
    {
        $this->inlineParameters = $inlineParameters;
    }

    /**
     * @return bool
     */
    public function hasInlineParameters() : bool
    {
        return $this->inlineParameters;
    }

    /**
     * Get the default post processor instance.
     *
     * @return Query\Processor
     */
    protected function getDefaultPostProcessor()
    {
        return new Query\Processor;
    }

    /**
     * Get the used bucket name.
     *
     * @return string
     */
    public function getBucketName()
    {
        return $this->bucketname;
    }

    /**
     * Begin a fluent query against a set of document types.
     *
     * @param  string $type
     * @return Query\Builder
     */
    public function builder($type)
    {
        $query = new QueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());

        return $query->from($type);
    }

    /**
     * @return QueryBuilder
     */
    public function query()
    {
        return $this->builder(null);
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param  string $query
     * @param  array $bindings
     * @return bool
     * @throws \Exception
     */
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $result = $this->runN1qlQuery($query, $bindings);

            return $result->status === 'success';
        });
    }

    /**
     * @param N1qlQuery $query
     *
     * @return mixed
     */
    protected function executeQuery(N1qlQuery $query)
    {
        return $this->bucket->query($query);
    }

    /**
     * {@inheritdoc}
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return $this->selectWithMeta($query, $bindings, $useReadPdo)->rows;
    }

    /**
     * {@inheritdoc}
     */
    public function selectWithMeta($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return [];
            }

            $result = $this->runN1qlQuery($query, $bindings);
            if (isset($result->rows)) {
                $result->rows = json_decode(json_encode($result->rows), true);
            }
            return $result;
        });
    }

    /**
     * @param string $query
     * @param array $bindings
     *
     * @return int|mixed
     * @throws \Exception
     */
    public function insert($query, $bindings = [])
    {
        return $this->statement($query, $bindings);
    }

    /**
     * Run an update statement against the database.
     *
     * @param string $query
     * @param array $bindings
     *
     * @return int|\stdClass
     * @throws \Exception
     */
    public function update($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * Run a delete statement against the database.
     *
     * @param string $query
     * @param array $bindings
     *
     * @return int|\stdClass
     * @throws \Exception
     */
    public function delete($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * @param       $query
     * @param array $bindings
     *
     * @return mixed
     * @throws \Exception
     */
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }
            $result = $this->runN1qlQuery($query, $bindings);
            $this->metrics = (isset($result->metrics)) ? $result->metrics : [];

            return (isset($result->rows[0])) ? $result->rows[0] : false;
        });
    }

    /**
     * @param string $n1ql
     * @param array $bindings
     * @return mixed
     */
    protected function runN1qlQuery(string $n1ql, array $bindings) {
        if($this->hasInlineParameters()) {
            $n1ql = $this->getQueryGrammar()->applyBindings($n1ql, $bindings);
            $bindings = [];
        }

        $options = new QueryOptions();
        $options->positionalParameters($bindings);
        // TODO $query->namedParams(['parameters' => $bindings]);

        $isSuccessFul = false;
        try {
            $result = $this->cluster->query($n1ql, $options);
            $rows = $result->rows();      
            $result = (array) $result;
            $result['rows'] = $rows;
            $result = (object) $result;
            $isSuccessFul = true;
        } finally {
            $this->logQueryFired($n1ql, [
                'positionalParams' => $bindings,
                'isSuccessful' => $isSuccessFul
            ]);
        }
        return $result;
    }

    /**
     * @param string $query
     * @param array $options
     */
    public function logQueryFired(string $query, array $options)
    {
        $this->event(new QueryFired($query, $options));
    }

    /**
     * Begin a fluent query against documents with given type.
     *
     * @param  string $table
     * @return Query\Builder
     */
    public function type($table)
    {
        return $this->builder($table);
    }

    /**
     * Begin a fluent query against documents with given type.
     *
     * @param  string $table
     * @return Query\Builder
     */
    public function table($table, $as = null)
    {
        return $this->builder($table);
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return Schema\Builder
     */
    public function getSchemaBuilder()
    {
        return new Schema\Builder($this);
    }

    /**
     * Get the Couchbase bucket object.
     *
     * @return \CouchbaseBucket
     */
    public function getCouchbaseBucket()
    {
        return $this->bucket;
    }

    /**
     * Get the query grammar used by the connection.
     *
     * @return QueryGrammar
     */
    public function getQueryGrammar() : QueryGrammar
    {
        return $this->queryGrammar;
    }

    /**
     * return CouchbaseCluster object.
     *
     * @return \CouchbaseCluster
     */
    public function getCouchbaseCluster()
    {
        return $this->connection;
    }

    /**
     * Create a new Couchbase connection.
     *
     * @param  string $dsn
     * @param  array $config
     * @return \CouchbaseCluster
     */
    protected function createConnection($dsn, array $config)
    {
        $connectionString = "couchbase://".$config['host'];
        if (!empty($config['username']) && !empty($config['password'])) {
            $options = new \Couchbase\ClusterOptions();
            $options->credentials($config['username'], $config['password']);
            $cluster = new \Couchbase\Cluster($connectionString, $options);
        }
        return $this->cluster = $cluster;
    }

    /**
     * Disconnect from the underlying Couchbase connection.
     */
    public function disconnect()
    {
        unset($this->connection);
    }

    /**
     * Create a DSN string from a configuration.
     *
     * @param  array $config
     * @return string
     */
    protected function getDsn(array $config)
    {
        // Check if the user passed a complete dsn to the configuration.
        if (!empty($config['dsn'])) {
            return $config['dsn'];
        }

        // Treat host option as array of hosts
        $hosts = is_array($config['host']) ? $config['host'] : [$config['host']];

        foreach ($hosts as &$host) {
            // Check if we need to add a port to the host
            if (strpos($host, ':') === false && !empty($config['port'])) {
                $host = $host . ':' . $config['port'];
            }
        }

        return 'couchbase://' . implode(',', $hosts);
    }

    /**
     * Get the elapsed time since a given starting point.
     *
     * @param  int $start
     * @return float
     */
    public function getElapsedTime($start)
    {
        return parent::getElapsedTime($start);
    }

    /**
     * Get the PDO driver name.
     *
     * @return string
     */
    public function getDriverName()
    {
        return 'couchbase';
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return Schema\Grammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return new Schema\Grammar;
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return Query\Grammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new Query\Grammar();
    }

    /**
     * Dynamically pass methods to the connection.
     *
     * @param  string $method
     * @param  array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return call_user_func_array([$this->bucket, $method], $parameters);
    }
}
