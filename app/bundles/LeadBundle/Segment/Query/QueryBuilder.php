<?php

namespace Mautic\LeadBundle\Segment\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ForwardCompatibility\Result;
use Mautic\LeadBundle\Segment\Query\Expression\CompositeExpression;
use Mautic\LeadBundle\Segment\Query\Expression\ExpressionBuilder;

/**
 * QueryBuilder class is responsible to dynamically create SQL queries.
 *
 * Important: Verify that every feature you use will work with your database vendor.
 * SQL Query Builder does not attempt to validate the generated SQL at all.
 *
 * The query builder does no validation whatsoever if certain features even work with the
 * underlying database vendor. Limit queries and joins are NOT applied to UPDATE and DELETE statements
 * even if some vendors such as MySQL support it.
 *
 * @see    www.doctrine-project.org
 * @since  2.1
 *
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Jan Kozak <galvani78@gmail.com>
 */
class QueryBuilder extends \Doctrine\DBAL\Query\QueryBuilder
{
    /*
     * The query types.
     */
    public const SELECT = 0;
    public const DELETE = 1;
    public const UPDATE = 2;
    public const INSERT = 3;

    /*
     * The builder states.
     */
    public const STATE_DIRTY = 0;
    public const STATE_CLEAN = 1;

    /**
     * The DBAL Connection.
     *
     * @var \Doctrine\DBAL\Connection
     */
    private $connection;

    /**
     * @var ExpressionBuilder
     */
    private $_expr;

    /**
     * @var array the array of SQL parts collected
     */
    private $sqlParts = [
        'select'  => [],
        'from'    => [],
        'join'    => [],
        'set'     => [],
        'where'   => null,
        'groupBy' => [],
        'having'  => null,
        'orderBy' => [],
        'values'  => [],
    ];

    /**
     * Unprocessed logic for segment processing.
     *
     * @var array
     */
    private $logicStack = [];

    /**
     * The complete SQL string for this query.
     *
     * @var string
     */
    private $sql;

    /**
     * The query parameters.
     *
     * @var array
     */
    private $params = [];

    /**
     * The parameter type map of this query.
     *
     * @var array
     */
    private $paramTypes = [];

    /**
     * The type of query this is. Can be select, update or delete.
     *
     * @var int
     */
    private $type = self::SELECT;

    /**
     * The state of the query object. Can be dirty or clean.
     *
     * @var int
     */
    private $state = self::STATE_CLEAN;

    /**
     * The index of the first result to retrieve.
     *
     * @var int
     */
    private $firstResult;

    /**
     * The maximum number of results to retrieve.
     *
     * @var int
     */
    private $maxResults;

    /**
     * The counter of bound parameters used with {@see bindValue).
     *
     * @var int
     */
    private $boundCounter = 0;

    /**
     * Initializes a new <tt>QueryBuilder</tt>.
     *
     * @param \Doctrine\DBAL\Connection $connection the DBAL Connection
     */
    public function __construct(Connection $connection)
    {
        parent::__construct($connection);
        $this->connection = $connection;
    }

    /**
     * Gets an ExpressionBuilder used for object-oriented construction of query expressions.
     * This producer method is intended for convenient inline usage. Example:.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u')
     *         ->from('users', 'u')
     *         ->where($qb->expr()->eq('u.id', 1));
     * </code>
     *
     * For more complex expression construction, consider storing the expression
     * builder object in a local variable.
     *
     * @return ExpressionBuilder
     */
    public function expr()
    {
        if (!is_null($this->_expr)) {
            return $this->_expr;
        }

        $this->_expr = new ExpressionBuilder($this->connection);

        return $this->_expr;
    }

    /**
     * Gets the type of the currently built query.
     *
     * @return int
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Gets the associated DBAL Connection for this query builder.
     *
     * @return \Doctrine\DBAL\Connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Gets the state of this query builder instance.
     *
     * @return int either QueryBuilder::STATE_DIRTY or QueryBuilder::STATE_CLEAN
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Executes this query using the bound parameters and their types.
     *
     * @return Result<mixed>|int|string
     *
     * @throws \Exception
     */
    public function execute()
    {
        if (self::SELECT === $this->type) {
            return Result::ensure(
                $this->connection->executeQuery($this->getSQL(), $this->params, $this->paramTypes)
            );
        }

        return $this->connection->executeStatement($this->getSQL(), $this->params, $this->paramTypes);
    }

    /**
     * Gets the complete SQL string formed by the current specifications of this QueryBuilder.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *     echo $qb->getSQL(); // SELECT u FROM User u
     * </code>
     *
     * @return string the SQL query string
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function getSQL()
    {
        if (null !== $this->sql && self::STATE_CLEAN === $this->state) {
            return $this->sql;
        }

        switch ($this->type) {
            case self::INSERT:
                $sql = $this->getSQLForInsert();
                break;
            case self::DELETE:
                $sql = $this->getSQLForDelete();
                break;

            case self::UPDATE:
                $sql = $this->getSQLForUpdate();
                break;

            case self::SELECT:
            default:
                $sql = $this->getSQLForSelect();
                break;
        }

        $this->state = self::STATE_CLEAN;
        $this->sql   = $sql;

        return $sql;
    }

    /**
     * Sets a query parameter for the query being constructed.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u')
     *         ->from('users', 'u')
     *         ->where('u.id = :user_id')
     *         ->setParameter(':user_id', 1);
     * </code>
     *
     * @param string|int  $key   the parameter position or name
     * @param mixed       $value the parameter value
     * @param string|null $type  one of the PDO::PARAM_* constants
     *
     * @return $this this QueryBuilder instance
     */
    public function setParameter($key, $value, $type = null)
    {
        if (':' === substr($key, 0, 1)) {
            // For consistency sake, remove the :
            $key = substr($key, 1);
        }

        if (is_bool($value)) {
            $value = (int) $value;
        }

        if (null !== $type) {
            $this->paramTypes[$key] = $type;
        }

        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Sets a collection of query parameters for the query being constructed.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u')
     *         ->from('users', 'u')
     *         ->where('u.id = :user_id1 OR u.id = :user_id2')
     *         ->setParameters(array(
     *             ':user_id1' => 1,
     *             ':user_id2' => 2
     *         ));
     * </code>
     *
     * @param array $params the query parameters to set
     * @param array $types  the query parameters types to set
     *
     * @return $this this QueryBuilder instance
     */
    public function setParameters(array $params, array $types = [])
    {
        $this->paramTypes = $types;
        $this->params     = $params;

        return $this;
    }

    /**
     * Gets all defined query parameters for the query being constructed indexed by parameter index or name.
     *
     * @return array the currently defined query parameters indexed by parameter index or name
     */
    public function getParameters()
    {
        return $this->params;
    }

    /**
     * Gets a (previously set) query parameter of the query being constructed.
     *
     * @param mixed $key the key (index or name) of the bound parameter
     *
     * @return mixed the value of the bound parameter
     */
    public function getParameter($key)
    {
        return isset($this->params[$key]) ? $this->params[$key] : null;
    }

    /**
     * Gets all defined query parameter types for the query being constructed indexed by parameter index or name.
     *
     * @return array the currently defined query parameter types indexed by parameter index or name
     */
    public function getParameterTypes()
    {
        return $this->paramTypes;
    }

    /**
     * Gets a (previously set) query parameter type of the query being constructed.
     *
     * @param mixed $key the key (index or name) of the bound parameter type
     *
     * @return mixed the value of the bound parameter type
     */
    public function getParameterType($key)
    {
        return isset($this->paramTypes[$key]) ? $this->paramTypes[$key] : null;
    }

    /**
     * Sets the position of the first result to retrieve (the "offset").
     *
     * @param int $firstResult the first result to return
     *
     * @return $this this QueryBuilder instance
     */
    public function setFirstResult($firstResult)
    {
        $this->state       = self::STATE_DIRTY;
        $this->firstResult = $firstResult;

        return $this;
    }

    /**
     * Gets the position of the first result the query object was set to retrieve (the "offset").
     * Returns NULL if {@link setFirstResult} was not applied to this QueryBuilder.
     *
     * @return int the position of the first result
     */
    public function getFirstResult()
    {
        return $this->firstResult;
    }

    /**
     * Sets the maximum number of results to retrieve (the "limit").
     *
     * @param int $maxResults the maximum number of results to retrieve
     *
     * @return $this this QueryBuilder instance
     */
    public function setMaxResults($maxResults)
    {
        $this->state      = self::STATE_DIRTY;
        $this->maxResults = $maxResults;

        return $this;
    }

    /**
     * Gets the maximum number of results the query object was set to retrieve (the "limit").
     * Returns NULL if {@link setMaxResults} was not applied to this query builder.
     *
     * @return int the maximum number of results
     */
    public function getMaxResults()
    {
        return $this->maxResults;
    }

    /**
     * Either appends to or replaces a single, generic query part.
     *
     * The available parts are: 'select', 'from', 'set', 'where',
     * 'groupBy', 'having' and 'orderBy'.
     *
     * @param string $sqlPartName
     * @param string $sqlPart
     * @param bool   $append
     *
     * @return $this this QueryBuilder instance
     */
    public function add($sqlPartName, $sqlPart, $append = false)
    {
        $isArray    = is_array($sqlPart);
        $isMultiple = is_array($this->sqlParts[$sqlPartName]);

        if ($isMultiple && !$isArray) {
            $sqlPart = [$sqlPart];
        }

        $this->state = self::STATE_DIRTY;

        if ($append) {
            if ('orderBy' == $sqlPartName || 'groupBy' == $sqlPartName || 'select' == $sqlPartName || 'set' == $sqlPartName) {
                foreach ($sqlPart as $part) {
                    $this->sqlParts[$sqlPartName][] = $part;
                }
            } elseif ($isArray && is_array($sqlPart[key($sqlPart)])) {
                $key                                  = key($sqlPart);
                $this->sqlParts[$sqlPartName][$key][] = $sqlPart[$key];
            } elseif ($isMultiple) {
                $this->sqlParts[$sqlPartName][] = $sqlPart;
            } else {
                $this->sqlParts[$sqlPartName] = $sqlPart;
            }

            return $this;
        }

        $this->sqlParts[$sqlPartName] = $sqlPart;

        return $this;
    }

    /**
     * Specifies an item that is to be returned in the query result.
     * Replaces any previously specified selections, if any.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u.id', 'p.id')
     *         ->from('users', 'u')
     *         ->leftJoin('u', 'phonenumbers', 'p', 'u.id = p.user_id');
     * </code>
     *
     * @param mixed $select the selection expressions
     *
     * @return $this this QueryBuilder instance
     */
    public function select($select = null)
    {
        $this->type = self::SELECT;

        if (empty($select)) {
            return $this;
        }

        $selects = is_array($select) ? $select : func_get_args();

        return $this->add('select', $selects);
    }

    /**
     * Adds an item that is to be returned in the query result.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u.id')
     *         ->addSelect('p.id')
     *         ->from('users', 'u')
     *         ->leftJoin('u', 'phonenumbers', 'u.id = p.user_id');
     * </code>
     *
     * @param mixed $select the selection expression
     *
     * @return $this this QueryBuilder instance
     */
    public function addSelect($select = null)
    {
        $this->type = self::SELECT;

        if (empty($select)) {
            return $this;
        }

        $selects = is_array($select) ? $select : func_get_args();

        return $this->add('select', $selects, true);
    }

    /**
     * Turns the query being built into a bulk delete query that ranges over
     * a certain table.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->delete('users', 'u')
     *         ->where('u.id = :user_id');
     *         ->setParameter(':user_id', 1);
     * </code>
     *
     * @param string $delete the table whose rows are subject to the deletion
     * @param string $alias  the table alias used in the constructed query
     *
     * @return $this this QueryBuilder instance
     */
    public function delete($delete = null, $alias = null)
    {
        $this->type = self::DELETE;

        if (!$delete) {
            return $this;
        }

        return $this->add('from', [
            'table' => $delete,
            'alias' => $alias,
        ]);
    }

    /**
     * Turns the query being built into a bulk update query that ranges over
     * a certain table.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->update('users', 'u')
     *         ->set('u.password', md5('password'))
     *         ->where('u.id = ?');
     * </code>
     *
     * @param string $update the table whose rows are subject to the update
     * @param string $alias  the table alias used in the constructed query
     *
     * @return $this this QueryBuilder instance
     */
    public function update($update = null, $alias = null)
    {
        $this->type = self::UPDATE;

        if (!$update) {
            return $this;
        }

        return $this->add('from', [
            'table' => $update,
            'alias' => $alias,
        ]);
    }

    /**
     * Turns the query being built into an insert query that inserts into
     * a certain table.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->insert('users')
     *         ->values(
     *             array(
     *                 'name' => '?',
     *                 'password' => '?'
     *             )
     *         );
     * </code>
     *
     * @param string $insert the table into which the rows should be inserted
     *
     * @return $this this QueryBuilder instance
     */
    public function insert($insert = null)
    {
        $this->type = self::INSERT;

        if (!$insert) {
            return $this;
        }

        return $this->add('from', [
            'table' => $insert,
        ]);
    }

    /**
     * Creates and adds a query root corresponding to the table identified by the
     * given alias, forming a cartesian product with any existing query roots.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u.id')
     *         ->from('users', 'u')
     * </code>
     *
     * @param string      $from  the table
     * @param string|null $alias the alias of the table
     *
     * @return $this this QueryBuilder instance
     */
    public function from($from, $alias = null)
    {
        return $this->add('from', [
            'table' => $from,
            'alias' => $alias,
        ], true);
    }

    /**
     * Creates and adds a join to the query.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u.name')
     *         ->from('users', 'u')
     *         ->join('u', 'phonenumbers', 'p', 'p.is_primary = 1');
     * </code>
     *
     * @param string $fromAlias the alias that points to a from clause
     * @param string $join      the table name to join
     * @param string $alias     the alias of the join table
     * @param string $condition the condition for the join
     *
     * @return $this this QueryBuilder instance
     */
    public function join($fromAlias, $join, $alias, $condition = null)
    {
        return $this->innerJoin($fromAlias, $join, $alias, $condition);
    }

    /**
     * Creates and adds a join to the query.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u.name')
     *         ->from('users', 'u')
     *         ->innerJoin('u', 'phonenumbers', 'p', 'p.is_primary = 1');
     * </code>
     *
     * @param string $fromAlias the alias that points to a from clause
     * @param string $join      the table name to join
     * @param string $alias     the alias of the join table
     * @param string $condition the condition for the join
     *
     * @return $this this QueryBuilder instance
     */
    public function innerJoin($fromAlias, $join, $alias, $condition = null)
    {
        return $this->add('join', [
            $fromAlias => [
                'joinType'      => 'inner',
                'joinTable'     => $join,
                'joinAlias'     => $alias,
                'joinCondition' => $condition,
            ],
        ], true);
    }

    /**
     * Creates and adds a left join to the query.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u.name')
     *         ->from('users', 'u')
     *         ->leftJoin('u', 'phonenumbers', 'p', 'p.is_primary = 1');
     * </code>
     *
     * @param string $fromAlias the alias that points to a from clause
     * @param string $join      the table name to join
     * @param string $alias     the alias of the join table
     * @param string $condition the condition for the join
     *
     * @return $this this QueryBuilder instance
     */
    public function leftJoin($fromAlias, $join, $alias, $condition = null)
    {
        return $this->add('join', [
            $fromAlias => [
                'joinType'      => 'left',
                'joinTable'     => $join,
                'joinAlias'     => $alias,
                'joinCondition' => $condition,
            ],
        ], true);
    }

    /**
     * Creates and adds a right join to the query.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u.name')
     *         ->from('users', 'u')
     *         ->rightJoin('u', 'phonenumbers', 'p', 'p.is_primary = 1');
     * </code>
     *
     * @param string $fromAlias the alias that points to a from clause
     * @param string $join      the table name to join
     * @param string $alias     the alias of the join table
     * @param string $condition the condition for the join
     *
     * @return $this this QueryBuilder instance
     */
    public function rightJoin($fromAlias, $join, $alias, $condition = null)
    {
        return $this->add('join', [
            $fromAlias => [
                'joinType'      => 'right',
                'joinTable'     => $join,
                'joinAlias'     => $alias,
                'joinCondition' => $condition,
            ],
        ], true);
    }

    /**
     * Sets a new value for a column in a bulk update query.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->update('users', 'u')
     *         ->set('u.password', md5('password'))
     *         ->where('u.id = ?');
     * </code>
     *
     * @param string $key   the column to set
     * @param string $value the value, expression, placeholder, etc
     *
     * @return $this this QueryBuilder instance
     */
    public function set($key, $value)
    {
        return $this->add('set', $key.' = '.$value, true);
    }

    /**
     * Specifies one or more restrictions to the query result.
     * Replaces any previously specified restrictions, if any.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u.name')
     *         ->from('users', 'u')
     *         ->where('u.id = ?');
     *
     *     // You can optionally programatically build and/or expressions
     *     $qb = $conn->createQueryBuilder();
     *
     *     $or = $qb->expr()->orx();
     *     $or->add($qb->expr()->eq('u.id', 1));
     *     $or->add($qb->expr()->eq('u.id', 2));
     *
     *     $qb->update('users', 'u')
     *         ->set('u.password', md5('password'))
     *         ->where($or);
     * </code>
     *
     * @param mixed $predicates the restriction predicates
     *
     * @return $this this QueryBuilder instance
     */
    public function where($predicates)
    {
        if (!(1 == func_num_args() && $predicates instanceof CompositeExpression)) {
            $predicates = new CompositeExpression(CompositeExpression::TYPE_AND, func_get_args());
        }

        return $this->add('where', $predicates);
    }

    /**
     * Adds one or more restrictions to the query results, forming a logical
     * conjunction with any previously specified restrictions.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u')
     *         ->from('users', 'u')
     *         ->where('u.username LIKE ?')
     *         ->andWhere('u.is_active = 1');
     * </code>
     *
     * @param mixed $where the query restrictions
     *
     * @return $this this QueryBuilder instance
     *
     * @see where()
     */
    public function andWhere($where)
    {
        $args  = func_get_args();
        $where = $this->getQueryPart('where');

        if ($where instanceof CompositeExpression && CompositeExpression::TYPE_AND === $where->getType()) {
            $where->addMultiple($args);
        } else {
            array_unshift($args, $where);
            $where = new CompositeExpression(CompositeExpression::TYPE_AND, $args);
        }

        return $this->add('where', $where, true);
    }

    /**
     * Adds one or more restrictions to the query results, forming a logical
     * disjunction with any previously specified restrictions.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u.name')
     *         ->from('users', 'u')
     *         ->where('u.id = 1')
     *         ->orWhere('u.id = 2');
     * </code>
     *
     * @param mixed $where the WHERE statement
     *
     * @return $this this QueryBuilder instance
     *
     * @see where()
     */
    public function orWhere($where)
    {
        $args  = func_get_args();
        $where = $this->getQueryPart('where');

        if ($where instanceof CompositeExpression && CompositeExpression::TYPE_OR === $where->getType()) {
            $where->addMultiple($args);
        } else {
            array_unshift($args, $where);
            $where = new CompositeExpression(CompositeExpression::TYPE_OR, $args);
        }

        return $this->add('where', $where, true);
    }

    /**
     * Specifies a grouping over the results of the query.
     * Replaces any previously specified groupings, if any.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u.name')
     *         ->from('users', 'u')
     *         ->groupBy('u.id');
     * </code>
     *
     * @param mixed $groupBy the grouping expression
     *
     * @return $this this QueryBuilder instance
     */
    public function groupBy($groupBy)
    {
        if (empty($groupBy)) {
            return $this;
        }

        $groupBy = is_array($groupBy) ? $groupBy : func_get_args();

        return $this->add('groupBy', $groupBy);
    }

    /**
     * Adds a grouping expression to the query.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u.name')
     *         ->from('users', 'u')
     *         ->groupBy('u.lastLogin');
     *         ->addGroupBy('u.createdAt')
     * </code>
     *
     * @param mixed $groupBy the grouping expression
     *
     * @return $this this QueryBuilder instance
     */
    public function addGroupBy($groupBy)
    {
        if (empty($groupBy)) {
            return $this;
        }

        $groupBy = is_array($groupBy) ? $groupBy : func_get_args();

        return $this->add('groupBy', $groupBy, true);
    }

    /**
     * Sets a value for a column in an insert query.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->insert('users')
     *         ->values(
     *             array(
     *                 'name' => '?'
     *             )
     *         )
     *         ->setValue('password', '?');
     * </code>
     *
     * @param string $column the column into which the value should be inserted
     * @param string $value  the value that should be inserted into the column
     *
     * @return $this this QueryBuilder instance
     */
    public function setValue($column, $value)
    {
        $this->sqlParts['values'][$column] = $value;

        return $this;
    }

    /**
     * Specifies values for an insert query indexed by column names.
     * Replaces any previous values, if any.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->insert('users')
     *         ->values(
     *             array(
     *                 'name' => '?',
     *                 'password' => '?'
     *             )
     *         );
     * </code>
     *
     * @param array $values the values to specify for the insert query indexed by column names
     *
     * @return $this this QueryBuilder instance
     */
    public function values(array $values)
    {
        return $this->add('values', $values);
    }

    /**
     * Specifies a restriction over the groups of the query.
     * Replaces any previous having restrictions, if any.
     *
     * @param mixed $having the restriction over the groups
     *
     * @return $this this QueryBuilder instance
     */
    public function having($having)
    {
        if (!(1 == func_num_args() && $having instanceof CompositeExpression)) {
            $having = new CompositeExpression(CompositeExpression::TYPE_AND, func_get_args());
        }

        return $this->add('having', $having);
    }

    /**
     * Adds a restriction over the groups of the query, forming a logical
     * conjunction with any existing having restrictions.
     *
     * @param mixed $having the restriction to append
     *
     * @return $this this QueryBuilder instance
     */
    public function andHaving($having)
    {
        $args   = func_get_args();
        $having = $this->getQueryPart('having');

        if ($having instanceof CompositeExpression && CompositeExpression::TYPE_AND === $having->getType()) {
            $having->addMultiple($args);
        } else {
            array_unshift($args, $having);
            $having = new CompositeExpression(CompositeExpression::TYPE_AND, $args);
        }

        return $this->add('having', $having);
    }

    /**
     * Adds a restriction over the groups of the query, forming a logical
     * disjunction with any existing having restrictions.
     *
     * @param mixed $having the restriction to add
     *
     * @return $this this QueryBuilder instance
     */
    public function orHaving($having)
    {
        $args   = func_get_args();
        $having = $this->getQueryPart('having');

        if ($having instanceof CompositeExpression && CompositeExpression::TYPE_OR === $having->getType()) {
            $having->addMultiple($args);
        } else {
            array_unshift($args, $having);
            $having = new CompositeExpression(CompositeExpression::TYPE_OR, $args);
        }

        return $this->add('having', $having);
    }

    /**
     * Specifies an ordering for the query results.
     * Replaces any previously specified orderings, if any.
     *
     * @param string $sort  the ordering expression
     * @param string $order the ordering direction
     *
     * @return $this this QueryBuilder instance
     */
    public function orderBy($sort, $order = null)
    {
        return $this->add('orderBy', $sort.' '.(!$order ? 'ASC' : $order));
    }

    /**
     * Adds an ordering to the query results.
     *
     * @param string $sort  the ordering expression
     * @param string $order the ordering direction
     *
     * @return $this this QueryBuilder instance
     */
    public function addOrderBy($sort, $order = null)
    {
        return $this->add('orderBy', $sort.' '.(!$order ? 'ASC' : $order), true);
    }

    /**
     * Gets a query part by its name.
     *
     * @param string $queryPartName
     *
     * @return mixed
     */
    public function getQueryPart($queryPartName)
    {
        return $this->sqlParts[$queryPartName];
    }

    /**
     * Gets all query parts.
     *
     * @return array
     */
    public function getQueryParts()
    {
        return $this->sqlParts;
    }

    /**
     * Resets SQL parts.
     *
     * @param array|null $queryPartNames
     *
     * @return $this this QueryBuilder instance
     */
    public function resetQueryParts($queryPartNames = null)
    {
        if (is_null($queryPartNames)) {
            $queryPartNames = array_keys($this->sqlParts);
        }

        foreach ($queryPartNames as $queryPartName) {
            $this->resetQueryPart($queryPartName);
        }

        return $this;
    }

    /**
     * @param $queryPartName
     * @param $value
     *
     * @return $this
     */
    public function setQueryPart($queryPartName, $value)
    {
        $this->sqlParts[$queryPartName] = $value;
        $this->state                    = self::STATE_DIRTY;

        return $this;
    }

    /**
     * Resets a single SQL part.
     *
     * @param string $queryPartName
     *
     * @return $this this QueryBuilder instance
     */
    public function resetQueryPart($queryPartName)
    {
        $this->sqlParts[$queryPartName] = is_array($this->sqlParts[$queryPartName])
            ? [] : null;

        $this->state = self::STATE_DIRTY;

        return $this;
    }

    /**
     * @return string
     *
     * @throws \Doctrine\DBAL\Exception
     */
    private function getSQLForSelect()
    {
        $query = 'SELECT '.implode(', ', $this->sqlParts['select']);

        $query .= ($this->sqlParts['from'] ? ' FROM '.implode(', ', $this->getFromClauses()) : '')
            .(null !== $this->sqlParts['where'] ? ' WHERE '.($this->sqlParts['where']) : '')
            .($this->sqlParts['groupBy'] ? ' GROUP BY '.implode(', ', $this->sqlParts['groupBy']) : '')
            .(null !== $this->sqlParts['having'] ? ' HAVING '.($this->sqlParts['having']) : '')
            .($this->sqlParts['orderBy'] ? ' ORDER BY '.implode(', ', $this->sqlParts['orderBy']) : '');

        if ($this->isLimitQuery()) {
            return $this->connection->getDatabasePlatform()->modifyLimitQuery(
                $query,
                $this->maxResults,
                $this->firstResult
            );
        }

        return $query;
    }

    /**
     * @return array
     *
     * @throws QueryException
     */
    private function getFromClauses()
    {
        $fromClauses  = [];
        $knownAliases = [];

        // Loop through all FROM clauses
        foreach ($this->sqlParts['from'] as $from) {
            if (null === $from['alias']) {
                $tableSql       = $from['table'];
                $tableReference = $from['table'];
            } else {
                $tableSql       = $from['table'].' '.$from['alias'];
                $tableReference = $from['alias'];
            }

            $knownAliases[$tableReference] = true;

            $fromClauses[$tableReference] = $tableSql.$this->getSQLForJoins($tableReference, $knownAliases);
        }

        $this->verifyAllAliasesAreKnown($knownAliases);

        return $fromClauses;
    }

    /**
     * @throws QueryException
     */
    private function verifyAllAliasesAreKnown(array $knownAliases)
    {
        foreach ($this->sqlParts['join'] as $fromAlias => $joins) {
            if (!isset($knownAliases[$fromAlias])) {
                throw QueryException::unknownAlias($fromAlias, array_keys($knownAliases));
            }
        }
    }

    /**
     * @return bool
     */
    private function isLimitQuery()
    {
        return null !== $this->maxResults || null !== $this->firstResult;
    }

    /**
     * Converts this instance into an INSERT string in SQL.
     *
     * @return string
     */
    private function getSQLForInsert()
    {
        return 'INSERT INTO '.$this->sqlParts['from']['table'].
            ' ('.implode(', ', array_keys($this->sqlParts['values'])).')'.
            ' VALUES('.implode(', ', $this->sqlParts['values']).')';
    }

    /**
     * Converts this instance into an UPDATE string in SQL.
     *
     * @return string
     */
    private function getSQLForUpdate()
    {
        $table = $this->sqlParts['from']['table'].($this->sqlParts['from']['alias'] ? ' '.$this->sqlParts['from']['alias'] : '');

        return 'UPDATE '.$table
            .' SET '.implode(', ', $this->sqlParts['set'])
            .(null !== $this->sqlParts['where'] ? ' WHERE '.($this->sqlParts['where']) : '');
    }

    /**
     * Converts this instance into a DELETE string in SQL.
     *
     * @return string
     */
    private function getSQLForDelete()
    {
        $table = $this->sqlParts['from']['table'].($this->sqlParts['from']['alias'] ? ' '.$this->sqlParts['from']['alias'] : '');

        return 'DELETE FROM '.$table.(null !== $this->sqlParts['where'] ? ' WHERE '.($this->sqlParts['where']) : '');
    }

    /**
     * @return string
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function __toString()
    {
        return $this->getSQL();
    }

    /**
     * Creates a new named parameter and bind the value $value to it.
     *
     * This method provides a shortcut for PDOStatement::bindValue
     * when using prepared statements.
     *
     * The parameter $value specifies the value that you want to bind. If
     * $placeholder is not provided bindValue() will automatically create a
     * placeholder for you. An automatic placeholder will be of the name
     * ':dcValue1', ':dcValue2' etc.
     *
     * For more information see {@link http://php.net/pdostatement-bindparam}
     *
     * Example:
     * <code>
     * $value = 2;
     * $q->eq( 'id', $q->bindValue( $value ) );
     * $stmt = $q->executeQuery(); // executed with 'id = 2'
     * </code>
     *
     * @license New BSD License
     *
     * @see     http://www.zetacomponents.org
     *
     * @param mixed  $value
     * @param mixed  $type
     * @param string $placeHolder The name to bind with. The string must start with a colon ':'.
     *
     * @return string the placeholder name used
     */
    public function createNamedParameter($value, $type = \PDO::PARAM_STR, $placeHolder = null)
    {
        if (null === $placeHolder) {
            ++$this->boundCounter;
            $placeHolder = ':dcValue'.$this->boundCounter;
        }
        $this->setParameter(substr($placeHolder, 1), $value, $type);

        return $placeHolder;
    }

    /**
     * Creates a new positional parameter and bind the given value to it.
     *
     * Attention: If you are using positional parameters with the query builder you have
     * to be very careful to bind all parameters in the order they appear in the SQL
     * statement , otherwise they get bound in the wrong order which can lead to serious
     * bugs in your code.
     *
     * Example:
     * <code>
     *  $qb = $conn->createQueryBuilder();
     *  $qb->select('u.*')
     *     ->from('users', 'u')
     *     ->where('u.username = ' . $qb->createPositionalParameter('Foo', PDO::PARAM_STR))
     *     ->orWhere('u.username = ' . $qb->createPositionalParameter('Bar', PDO::PARAM_STR))
     * </code>
     *
     * @param mixed $value
     * @param int   $type
     *
     * @return string
     */
    public function createPositionalParameter($value, $type = \PDO::PARAM_STR)
    {
        ++$this->boundCounter;
        $this->setParameter($this->boundCounter, $value, $type);

        return '?';
    }

    /**
     * @param $fromAlias
     *
     * @return string
     *
     * @throws QueryException
     */
    private function getSQLForJoins($fromAlias, array &$knownAliases)
    {
        $sql = '';

        if (isset($this->sqlParts['join'][$fromAlias])) {
            foreach ($this->sqlParts['join'][$fromAlias] as $join) {
                if (array_key_exists($join['joinAlias'], $knownAliases)) {
                    throw QueryException::nonUniqueAlias($join['joinAlias'], array_keys($knownAliases));
                }
                $sql .= ' '.strtoupper($join['joinType'])
                    .' JOIN '.$join['joinTable'].' '.$join['joinAlias']
                    .' ON '.($join['joinCondition']);
                $knownAliases[$join['joinAlias']] = true;
            }

            foreach ($this->sqlParts['join'][$fromAlias] as $join) {
                $sql .= $this->getSQLForJoins($join['joinAlias'], $knownAliases);
            }
        }

        return $sql;
    }

    /**
     * Deep clone of all expression objects in the SQL parts.
     */
    public function __clone()
    {
        foreach ($this->sqlParts as $part => $elements) {
            if (is_array($this->sqlParts[$part])) {
                foreach ($this->sqlParts[$part] as $idx => $element) {
                    if (is_object($element)) {
                        $this->sqlParts[$part][$idx] = clone $element;
                    }
                }
            } elseif (is_object($elements)) {
                $this->sqlParts[$part] = clone $elements;
            }
        }

        foreach ($this->params as $name => $param) {
            if (is_object($param)) {
                $this->params[$name] = clone $param;
            }
        }
    }

    /**
     * @param $alias
     *
     * @return bool
     */
    public function getJoinCondition($alias)
    {
        $parts = $this->getQueryParts();
        foreach ($parts['join']['l'] as $joinedTable) {
            if ($joinedTable['joinAlias'] == $alias) {
                return $joinedTable['joinCondition'];
            }
        }

        return false;
    }

    /**
     * Add AND condition to existing table alias.
     *
     * @param $alias
     * @param $expr
     *
     * @return $this
     *
     * @throws QueryException
     */
    public function addJoinCondition($alias, $expr)
    {
        $result = $parts = $this->getQueryPart('join');

        foreach ($parts as $tbl => $joins) {
            foreach ($joins as $key => $join) {
                if ($join['joinAlias'] == $alias) {
                    $result[$tbl][$key]['joinCondition'] = $join['joinCondition'].' and ('.$expr.')';
                    $inserted                            = true;
                }
            }
        }

        if (!isset($inserted)) {
            throw new QueryException('Inserting condition to nonexistent join '.$alias);
        }

        $this->setQueryPart('join', $result);

        return $this;
    }

    /**
     * @param $alias
     * @param $expr
     *
     * @return $this
     */
    public function replaceJoinCondition($alias, $expr)
    {
        $parts = $this->getQueryPart('join');
        foreach ($parts['l'] as $key => $part) {
            if ($part['joinAlias'] == $alias) {
                $parts['l'][$key]['joinCondition'] = $expr;
            }
        }

        $this->setQueryPart('join', $parts);

        return $this;
    }

    /**
     * @param $parameters
     * @param $filterParameters
     *
     * @return QueryBuilder
     */
    public function setParametersPairs($parameters, $filterParameters)
    {
        if (!is_array($parameters)) {
            return $this->setParameter($parameters, $filterParameters);
        }

        foreach ($parameters as $parameter) {
            $parameterValue = array_shift($filterParameters);
            $this->setParameter($parameter, $parameterValue);
        }

        return $this;
    }

    /**
     * @param string $table
     * @param null   $joinType allowed values: inner, left, right
     *
     * @return array|bool|string
     */
    public function getTableAlias($table, $joinType = null)
    {
        if (is_null($joinType)) {
            $tables = $this->getTableAliases();

            return isset($tables[$table]) ? $tables[$table] : false;
        }

        $tableJoins = $this->getTableJoins($table);

        foreach ($tableJoins as $tableJoin) {
            if ($tableJoin['joinType'] == $joinType) {
                return $tableJoin['joinAlias'];
            }
        }

        return false;
    }

    public function getTableJoins($tableName)
    {
        $found = [];
        foreach ($this->getQueryParts()['join'] as $join) {
            foreach ($join as $joinPart) {
                if ($tableName == $joinPart['joinTable']) {
                    $found[] = $joinPart;
                }
            }
        }

        return count($found) ? $found : [];
    }

    /**
     * Functions returns either the 'lead.id' or the primary key from right joined table.
     *
     * @return string
     */
    public function guessPrimaryLeadContactIdColumn()
    {
        $parts     = $this->getQueryParts();
        $leadTable = $parts['from'][0]['alias'];

        if ('orp' === $leadTable) {
            return 'orp.lead_id';
        }

        if (!isset($parts['join'][$leadTable])) {
            return $leadTable.'.id';
        }

        $joins     = $parts['join'][$leadTable];

        foreach ($joins as $join) {
            if ('right' == $join['joinType']) {
                $matches = null;
                if (preg_match('/'.$leadTable.'\.id \= ([^\ ]+)/i', $join['joinCondition'], $matches)) {
                    return $matches[1];
                }
            }
        }

        return $leadTable.'.id';
    }

    /**
     * Return aliases of all currently registered tables.
     *
     * @return array
     */
    public function getTableAliases()
    {
        $queryParts = $this->getQueryParts();
        $tables     = array_reduce($queryParts['from'], function ($result, $item) {
            $result[$item['table']] = $item['alias'];

            return $result;
        }, []);

        foreach ($queryParts['join'] as $join) {
            foreach ($join as $joinPart) {
                $tables[$joinPart['joinTable']] = $joinPart['joinAlias'];
            }
        }

        return $tables;
    }

    /**
     * @param $table
     *
     * @return bool
     */
    public function isJoinTable($table)
    {
        $queryParts = $this->getQueryParts();

        foreach ($queryParts['join'] as $join) {
            foreach ($join as $joinPart) {
                if ($joinPart['joinTable'] == $table) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return mixed|string
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function getDebugOutput()
    {
        $params = $this->getParameters();
        $sql    = $this->getSQL();
        foreach ($params as $key=>$val) {
            if (!is_int($val) && !is_float($val) && !is_array($val)) {
                $val = "'$val'";
            } elseif (is_array($val)) {
                if (Connection::PARAM_STR_ARRAY === $this->getParameterType($key)) {
                    $val = array_map(fn ($value) => "'$value'", $val);
                }
                $val = join(', ', $val);
            }
            $sql = str_replace(":{$key}", $val, $sql);
        }

        return $sql;
    }

    /**
     * @return bool
     */
    public function hasLogicStack()
    {
        return count($this->logicStack) > 0;
    }

    /**
     * @return array
     */
    public function getLogicStack()
    {
        return $this->logicStack;
    }

    /**
     * @return array
     */
    public function popLogicStack()
    {
        $stack            = $this->logicStack;
        $this->logicStack = [];

        return $stack;
    }

    /**
     * @param $expression
     *
     * @return $this
     */
    private function addLogicStack($expression)
    {
        $this->logicStack[] = $expression;

        return $this;
    }

    /**
     * This function assembles correct logic for segment processing, this is to replace andWhere and orWhere (virtualy
     *  as they need to be kept). You may not use andWhere in filters!!!
     *
     * @param $expression
     * @param $glue
     */
    public function addLogic($expression, $glue)
    {
        // little setup
        $glue = strtolower($glue);

        //  Different handling
        if ('or' == $glue) {
            //  Is this the first condition in query builder?
            if (!is_null($this->sqlParts['where'])) {
                // Are the any queued conditions?
                if ($this->hasLogicStack()) {
                    // We need to apply current stack to the query builder
                    $this->applyStackLogic();
                }
                // We queue current expression to stack
                $this->addLogicStack($expression);
            } else {
                $this->andWhere($expression);
            }
        } else {
            //  Glue is AND
            if ($this->hasLogicStack()) {
                $this->addLogicStack($expression);
            } else {
                $this->andWhere($expression);
            }
        }
    }

    /**
     * Apply content of stack.
     *
     * @return $this
     */
    public function applyStackLogic()
    {
        if ($this->hasLogicStack()) {
            $stackGroupExpression = new CompositeExpression(CompositeExpression::TYPE_AND, $this->popLogicStack());
            $this->orWhere($stackGroupExpression);
        }

        return $this;
    }

    /**
     * @return QueryBuilder
     */
    public function createQueryBuilder(Connection $connection = null)
    {
        if (null === $connection) {
            $connection = $this->getConnection();
        }

        return new self($connection);
    }
}
