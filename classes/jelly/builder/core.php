<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Jelly_Builder is a class used for query building. It handles
 * automatic aliasing of all models and columns (but also supports
 * unknown models and fields).
 * 
 * Because of the limitations of PHP and Kohana's class structure,
 * it must extend a Database_Query_Builder_Select. However, the 
 * instance is properly transposed into its actual type when compiled
 * or executed.
 * 
 * It is possible to use un-executed() query builder instances in other
 * query builder statements, just as you would with Kohana's native
 * facilities.
 *
 * @package Jelly
 */
abstract class Jelly_Builder_Core extends Kohana_Database_Query_Builder_Select implements Iterator, Countable, SeekableIterator, ArrayAccess
{	
	/**
	 * @var string The inital model used to construct the builder
	 */
	protected $_model = NULL;
	
	/**
	 * @var Jelly_Meta The meta object (if found) that is attached to this builder
	 */	
	protected $_meta = NULL;
	
	/**
	 * @var array Data to be UPDATEd
	 */
	protected $_set = array();
	
	/**
	 * @var array Columns to be INSERTed
	 */
	protected $_columns = array();
	
	/**
	 * @var array Values to be INSERTed
	 */
	protected $_values = array();
	
	/**
	 * @var int The query type, one of the Database constants
	 */
	protected $_type = NULL;
	
	/**
	 * @var boolean The result, if the query has been executed
	 */
	protected $_result = NULL;
	
	/**
	 * @var	Jelly_Model Instance of model populated and returned whilst iterating
	 */
	protected $_model_instance = NULL;
	
	/**
	 * Constructs a new Jelly_Builder instance. 
	 * 
	 * $model is not actually allowed to be NULL. It has
	 * a default because PHP throws strict errors otherwise.
	 *
	 * @param string $model
	 */
	public function __construct($model = NULL, $type = NULL)
	{
		parent::__construct();
		
		if ( ! $model)
		{
			throw new Kohana_Exception(get_class($this) . ' requires $model to be set in the constructor');
		}
		
		// Set the model and the initial from()
		$this->_model = $model;
		$this->_register_model();
		
		// Default to loading as arrays
		$this->as_object(FALSE);
		
		// Save this for building the query later on
		$this->_type = $type;
	}
	
	/**
	 * Builds the builder into a native query
	 *
	 * @param string $type 
	 * @return void
	 */
	public function execute($db = 'default')
	{
		// Don't repeat queries
		if ( ! $this->_result)
		{
			// See if we can use a better $db group
			if ($this->_meta)
			{
				$db = $this->_meta->db();
			}
			
			// Apply sorting and with if necessary
			if ($this->_type === Database::SELECT && $this->_meta)
			{
				foreach ($this->_meta->sorting() as $column => $direction)
				{
					$this->order_by($column, $direction);
				}
				
				foreach ($this->_meta->load_with() as $relationship)
				{
					$this->with($relationship);
				}
			}
			
			// We've now left the Jelly
			$this->_result = $this->_build()->execute($db);
		}
		
		// If result is a model instance, return it directly
		if ($this->_type === Database::SELECT AND $this->_limit === 1)
		{
			return $this->current();
		}
		
		// Return $this for iteration over result set
		return $this;
	}
	
	/**
	 * Compiles the builder into a usable expression
	 *
	 * @param  Database $db 
	 * @return Database_Query
	 */
	public function compile(Database $db)
	{
		return $this->_build()->compile($db);
	}
	
	/**
	 * Counts the current query builder result
	 *
	 * @return int
	 */
	public function count_results()
	{
		$query = $this->_build(Database::SELECT);
		$db = (is_object($this->_meta)) ? $this->_meta->db() : 'default';
		
		// Find the count
		return (int) $query
						->select(array('COUNT("*")', 'total'))
						->execute($db)
						->get('total');
	}
	
	/**
	 * Returns the current query limited to 1 and 
	 * executed, if it is a Database::SELECT.
	 *
	 * @param  mixed $key 
	 * @return Jelly_Model
	 */
	public function load($key = NULL)
	{
		if ($this->_type === Database::SELECT)
		{
			if ($key)
			{
				$this->where(':unique_key', '=', $key);
			}

			return $this->limit(1)->execute();
		}
		
		return $this;
	}
	
	/**
	 * Returns the unique key for a specific value. This method is expected 
	 * to be overloaded in builders if the table has other unique columns.
	 *
	 * @param  mixed  $value 
	 * @return string
	 */
	public function unique_key($value)
	{
		return $this->_meta->primary_key();
	}

	/**
	 * Creates a new "AND WHERE" condition for the query.
	 *
	 * @param   mixed   column name or array($column, $alias) or object
	 * @param   string  logic operator
	 * @param   mixed   column value
	 * @return  $this
	 */
	public function and_where($column, $op, $value)
	{
		return parent::and_where($this->_column($column, TRUE, $value), $op, $value);
	}

	/**
	 * Creates a new "OR WHERE" condition for the query.
	 *
	 * @param   mixed   column name or array($column, $alias) or object
	 * @param   string  logic operator
	 * @param   mixed   column value
	 * @return  $this
	 */
	public function or_where($column, $op, $value)
	{
		return parent::or_where($this->_column($column, TRUE, $value), $op, $value);
	}
	
	/**
	 * Choose the columns to select from.
	 *
	 * @param   mixed  column name or array($column, $alias) or object
	 * @param   ...
	 * @return  $this
	 */
	public function select($columns = NULL)
	{
		return $this->select_array(func_get_args());
	}

	/**
	 * Choose the columns to select from, using an array.
	 *
	 * @param   array  list of column names or aliases
	 * @return  $this
	 */
	public function select_array(array $columns)
	{
		foreach ($columns as $i => $column)
		{
			if (is_array($column))
			{
				$columns[$i][0] = $this->_column($column[0], TRUE);
			}
			else
			{
				$columns[$i] = $this->_column($column, TRUE);
			}
		}
		
		return parent::select_array($columns);
	}

	/**
	 * Choose the tables to select "FROM ..."
	 *
	 * @param   mixed  table name or array($table, $alias) or object
	 * @param   ...
	 * @return  $this
	 */
	public function from($tables)
	{
		$tables = func_get_args();
		
		foreach ($tables as $i => $table)
		{
			// Cache the first meta
			if ( ! $this->_meta)
			{
				$model = $table;
				
				if (is_array($model))
				{
					$model = $model[0];
				}
				
				if ($model = Jelly::meta($model))
				{
					$this->_meta = $model;
				}
			}
			
			if (is_array($table))
			{
				$table[0] = $this->_table($table[0]);
			}
			else
			{
				$table = $this->_table($table);
			}
			
			parent::from($table);
		}
		
		return $this;
	}

	/**
	 * Adds addition tables to "JOIN ...".
	 *
	 * @param   mixed   column name or array($column, $alias) or object
	 * @param   string  join type (LEFT, RIGHT, INNER, etc)
	 * @return  $this
	 */
	public function join($table, $type = NULL)
	{
		if (is_array($table))
		{
			$table[0] = $this->_table($table[0]);
		}
		else
		{
			$table = $this->_table($table);
		}
		
		return parent::join($table, $type);
	}

	/**
	 * Adds "ON ..." conditions for the last created JOIN statement.
	 *
	 * @param   mixed   column name or array($column, $alias) or object
	 * @param   string  logic operator
	 * @param   mixed   column name or array($column, $alias) or object
	 * @return  $this
	 */
	public function on($c1, $op, $c2)
	{
		return parent::on($this->_column($c1, TRUE), $op, $this->_column($c2, TRUE));
	}

	/**
	 * Creates a "GROUP BY ..." filter.
	 *
	 * @param   mixed   column name or array($column, $alias) or object
	 * @param   ...
	 * @return  $this
	 */
	public function group_by($columns)
	{
		$columns = func_get_args();
		
		foreach($columns as $i => $column)
		{
			if (is_array($column))
			{
				$columns[$i][0] = $this->_table($column[0]);
			}
			else
			{
				$columns[$i] = $this->_table($column);
			}	
		}

		return parent::group_by($columns);
	}

	/**
	 * Creates a new "AND HAVING" condition for the query.
	 *
	 * @param   mixed   column name or array($column, $alias) or object
	 * @param   string  logic operator
	 * @param   mixed   column value
	 * @return  $this
	 */
	public function and_having($column, $op, $value = NULL)
	{
		return parent::and_having($this->_column($column, TRUE, $value), $op, $value);
	}

	/**
	 * Creates a new "OR HAVING" condition for the query.
	 *
	 * @param   mixed   column name or array($column, $alias) or object
	 * @param   string  logic operator
	 * @param   mixed   column value
	 * @return  $this
	 */
	public function or_having($column, $op, $value = NULL)
	{
		return parent::or_having($this->_column($column, TRUE, $value), $op, $value);
	}

	/**
	 * Applies sorting with "ORDER BY ..."
	 *
	 * @param   mixed   column name or array($column, $alias) or object
	 * @param   string  direction of sorting
	 * @return  $this
	 */
	public function order_by($column, $direction = NULL)
	{
		return parent::order_by($this->_column($column, TRUE), $direction);
	}
	
	/**
	 * Set the values to update with an associative array.
	 *
	 * @param   array   associative (column => value) list
	 * @return  $this
	 */
	public function set(array $pairs, $alias = TRUE)
	{
		foreach ($pairs as $column => $value)
		{
			$this->value($column, $value, $alias);
		}

		return $this;
	}

	/**
	 * Set the value of a single column.
	 *
	 * @param   mixed  table name or array($table, $alias) or object
	 * @param   mixed  column value
	 * @return  $this
	 */
	public function value($column, $value, $alias = TRUE)
	{
		if ($alias)
		{
			$column = $this->_column($column, NULL, $value);
		}
		
		$this->_set[$column] = $value;

		return $this;
	}
	
	/**
	 * Set the columns that will be inserted.
	 *
	 * @param   array  column names
	 * @return  $this
	 */
	public function columns(array $columns, $alias = TRUE)
	{
		if ($alias)
		{
			foreach ($columns as $i => $column)
			{
				$columns[$i] = $this->_column($column);
			}
		}
		
		$this->_columns = $columns;

		return $this;
	}
	
	/**
	 * Sets values on an insert
	 *
	 * @param  array $values 
	 * @return $this
	 */
	public function values(array $values)
	{
		// Get all of the passed values
		$values = func_get_args();

		$this->_values = array_merge($this->_values, $values);

		return $this;
	}
	
	/**
	 * Allows joining 1:1 relationships in a single query.
	 * 
	 * It is possible to join a relationship to a join using
	 * the following syntax:
	 * 
	 * $post->join("author:role");
	 * 
	 * Assuming a post belongs to an author and an author has one role.
	 * 
	 * Currently, no checks are made to see if a join has already 
	 * been made, so joining a model twice will result in 
	 * a failed query.
	 *
	 * @param  string $alias 
	 * @return $this
	 */
	public function with($relationship)
	{
		// Ensure the main table is selected
		$this->select('*');
		
		// We'll start with the first one and work our way down
		$paths = explode(":", $relationship);
		$parent = $this->_meta->model();
		$chain = '';
		
		foreach ($paths as $iteration => $path)
		{
			$field = Jelly::meta($parent)->fields($path);

			if ( ! ($field instanceof Jelly_Field_Behavior_Joinable))
			{
				// Entire list is invalid
				break;
			}

			// If we're on the first iteration, the parent path is just the 
			// name of the model, otherwise we use the chain
			if ($iteration === 0)
			{
				$prev_chain = $parent;
			}
			else
			{
				$prev_chain = $chain;
			}
			
			$chain .= ":".$field->name;
					
			// Set the next iteration's parent
			$model = $field->foreign['model'];
			$meta = Jelly::meta($model);
			
			// Select all of the model's fields
			foreach ($meta->fields() as $alias => $select)
			{
				if ($select->in_db)
				{
					// We have to manually alias, since the path does not necessarily correspond to the path
					$this->select(array($model.'.'.$alias, $chain.':'.$alias));
				}
			}
			
			// Let the field finish the rest
			$field->with($this);
			
			// Model now becomes the parent
			$parent = $model;
		}
		
		return $this;
	}
	
	/**
	 * Resets the query builder to an empty state.
	 * 
	 * The query type and model is not reset.
	 *
	 * @return $this
	 */
	public function reset()
	{
		parent::reset();
		
		$this->_set     =
		$this->_columns = 
		$this->_values  = array();
		$this->_result = NULL;
		
		// Re-register the model
		$this->_register_model();
		
		return $this;
	}
	
	/**
	 * Sets the model and the initial from() clause
	 *
	 * @return void
	 */
	protected function _register_model()
	{
		// Hopefully we have a model to work with
		$this->_meta = Jelly::meta($this->_model);
		
		// Can we set the default from?
		if ($this->_meta)
		{
			$this->from($this->_meta->table());
		}
		else
		{
			$this->from($this->_model);
		}
		
		// Create an empty instance to return while iterating
		$this->_model_instance = Jelly::factory($this->_model);
	}
	
	/**
	 * This method aliases models to tables.
	 *
	 * @param  string	$table 
	 * @return string
	 */
	protected function _table($model)
	{
		if ($meta = Jelly::meta($model))
		{
			$model = $meta->table();
		}
		
		return $model;
	}
	
	/**
	 * This is an internal method used for aliasing only things coming 
	 * to the query builder, since they can come in so many formats.
	 * 
	 * $value is passed so the :unique_key meta alias can be used.
	 *
	 * @param  string	$field 
	 * @param  boolean	$join
	 * @param  mixed    $value
	 * @return string
	 */
	protected function _column($field, $join = NULL, $value = NULL)
	{
		$model = NULL;
		
		// Check for functions
		if (strpos($field, '"') !== FALSE)
		{
			// Quote the column in FUNC("ident") identifiers
			return preg_replace('/"(.+?)"/e', '"\\"".$this->_column("$1")."\\""', $field);
		}
		
		if (strpos($field, '.') !== FALSE)
		{			
			list($model, $field) = explode('.', $field);
			
			// If $join is NULL, the column is returned as it came
			// If it was joined when it came in, it returns joined
			if ($join === NULL)
			{
				$join = TRUE;
			}
		}
		
		// If the model is NULL, $this's table name or model name
		// We just replace if with the current model's name
		if ($this->_meta AND ($model === NULL OR $model == $this->_meta->table()))
		{
			$model = $this->_meta->model();
		}
		
		$table = $model;
		$column = $field;
		
		// See if the model is register
		if ($meta = Jelly::meta($model))
		{
			$table = $meta->table();

			// Find the field
			if ($meta->fields($field))
			{
				$column = $meta->fields($field)->column;
			}
		}
		
		// Check for a meta-alias
		if (FALSE !== strpos($field, ':'))
		{
			$column = $this->_meta_alias($meta, $field, $value);
		}
		
		// Only join when we actually have a table
		if ($join AND $table)
		{
			return $table.'.'.$column;
		}
		else
		{
			return $column;
		}
	}
	
	/**
	 * Expands meta-aliases into their actual field name.
	 * 
	 * This can be overridden to add meta aliases to your
	 * model's query builder. Keep in mind that they can
	 * only be used in query building and not in the model.
	 *
	 * @param  string  $alias
	 * @return string
	 */
	protected function _meta_alias($meta, $field, $value = NULL)
	{
		// Check for a model operator
		if (substr($field, 0, 1) !== ':')
		{
			list($model, $field) = explode(':', $field);
			
			// Append the : back onto $field, it's key for recognizing the alias below
			$field = ':'.$field;

			if (FALSE == ($meta = Jelly::meta($model)))
			{
				throw new Kohana_Exception('Meta data for :model was not found. Trying to locate a :foreign_key', array(
					':model' => $model));
			}
		}
		
		switch ($field)
		{
			case ':primary_key':
				$field = $meta->primary_key();
				break;
			case ':name_key';
				$field = $meta->name_key();
				break;
			case ':foreign_key':
				$field = $meta->foreign_key();
				break;
			case ':unique_key':
				$builder = $meta->builder();
				$builder = new $builder($meta->model());
				$field = $builder->unique_key($value);
				break;
			default:
				throw new Kohana_Exception('Unknown meta alias :field', array(
					':field' => $field));
		}
		
		if ($found = $meta->fields($field))
		{
			$field = $found->column;
		}		
		
		return $field;
	}
	
	/**
	 * Builders the instance into a usable 
	 * Database_Query_Builder_* instance.
	 *
	 * @return Database_Query_Builder
	 */
	protected function _build($type = NULL)
	{
		if ($type === NULL)
		{
			$type = $this->_type;
		}
		
		switch($type)
		{
			case Database::SELECT:
				$query = DB::select();
				$query->_from		= $this->_from;
				$query->_select 	= $this->_select;
				$query->_distinct	= $this->_distinct;
				$query->_offset 	= $this->_offset;
				$query->_limit 		= $this->_limit;
				$query->_join 		= $this->_join;
				$query->_group_by 	= $this->_group_by;
				$query->_order_by 	= $this->_order_by;
				$query->_as_object  = $this->_as_object;
				break;
				
			case Database::UPDATE:
				$query = DB::update(current($this->_from));
				break;
				
			case Database::INSERT:
				$query = DB::insert(current($this->_from));
				break;		
				
			case Database::DELETE:
				$query = DB::delete(current($this->_from));
				break;
				
			default:
				throw new Kohana_Exception("Jelly_Builder compiled without a query type specified");
				break;
		}
		
		// Copy over the common conditions to a new statement
		$query->_where = $this->_where;
		
		// Convert sets
		if ($this->_columns AND $this->_values AND $type === Database::INSERT)
		{
			$query->columns($this->_columns);
			
			// Have to do a call_user_func_array to support multiple sets
			call_user_func_array(array($query, 'values'), $this->_values);
		}
		
		if ($this->_set AND $type === Database::UPDATE)
		{
			$query->set($this->_set);
		}
		
		return $query;
	}
	
	/**
	 * Implementation of the Iterator interface
	 * @return $this
	 */
	public function rewind() 
	{
		// Rewind is the first interface method called when iterating, 
		// ensure we have executed the query
		$this->execute();
		
		$this->_result->rewind();
		return $this;
    }

	/**
	 * Implementation of the Iterator interface
	 * @return Jelly
	 */
    public function current($object = TRUE) 
	{	
		// Database_Result causes errors if you call current() 
		// on an object with no results, so we check first.	
		if ($this->_result->count())
		{
			$result = $this->_result->current();
		}
		else
		{
			$result = array();
		}
		
		if ($object AND $this->_model_instance)
		{
			// Don't return models when we don't have one
			$result = ($result) 
			        ? $this->_model_instance->load_values($result, TRUE) 
			        : $this->_model_instance->clear();
		}
		
		return $result;
    }

	/**
	 * Implementation of the Iterator interface
	 * @return int
	 */
    public function key() 
	{
        return $this->_result->key();
    }

	/**
	 * Implementation of the Iterator interface
	 * @return $this
	 */
    public function next() 
	{
        $this->_result->next();
		return $this;
    }

	/**
	 * Implementation of the Iterator interface
	 * @return boolean
	 */
    public function valid() 
	{
		return $this->_result->valid();
    }

	/**
	 * Implementation of the Countable interface
	 * @return boolean
	 */
    public function count() 
	{
		// Must do query before we can count
		$this->execute();
		
		return $this->_result->count();
    }

	/**
	 * Implementation of SeekableIterator
	 *
	 * @param  mixed   $offset 
	 * @return boolean
	 */
	public function seek($offset)
	{
		// Must do query before we can seek
		//$this->execute();
		
		return $this->_result->seek($offset);
	}

	/**
	 * ArrayAccess: offsetExists
	 */
	public function offsetExists($offset)
	{
		// Must do query before we can get at results
		$this->execute();
		
		return $this->_result->offsetExists($offset);
	}

	/**
	 * ArrayAccess: offsetGet
	 */
	public function offsetGet($offset)
	{
		// Must do query before we can get at results
		$this->execute();
		
		return $this->_result->offsetGet($offset);
	}

	/**
	 * ArrayAccess: offsetSet
	 *
	 * @throws  Kohana_Exception
	 */
	final public function offsetSet($offset, $value)
	{
		throw new Kohana_Exception('Jelly Collections are read-only');
	}

	/**
	 * ArrayAccess: offsetUnset
	 *
	 * @throws  Kohana_Exception
	 */
	final public function offsetUnset($offset)
	{
		throw new Kohana_Exception('Jelly Collections are read-only');
	}
}