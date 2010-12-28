<?php
/**
 * Optimizer
 * @file 		RedBean/Optimizer.php
 * @author			Gabor de Mooij
 * @license			BSD
 *
 *
 * (c) G.J.G.T. (Gabor) de Mooij
 * This source file is subject to the BSD/GPLv2 License that is bundled
 * with this source code in the file license.txt.
 */
class RedBean_Plugin_Optimizer extends RedBean_CompatManager implements RedBean_Plugin,RedBean_Observer {

	/**
	 * Specify what database systems are supported by this class.
	 * @var array $databaseSpecs
	 */
	protected $supportedSystems = array(
			  RedBean_CompatManager::C_SYSTEM_MYSQL => "5"
	);


	/**
	 * @var RedBean_Adapter_DBAdapter
	 * Contains a reference to the database adapter.
	 */
	private $adapter;

	/**
	 * @var RedBean_OODB
	 * Contains a reference to the RedBean OODB object.
	 */
	private $oodb;

	/**
	 * @var RedBean_QueryWriter_MySQL
	 * Contains a reference to the query writer.
	 */
	private $writer;

	/**
	 * Constructor
	 * Handles the toolbox
	 * 
	 * @param RedBean_ToolBox $toolbox
	 */
	public function __construct( RedBean_ToolBox $toolbox ) {
		$this->scanToolBox( $toolbox );
		$this->oodb = $toolbox->getRedBean();
		$this->adapter = $toolbox->getDatabaseAdapter();
		$this->writer = $toolbox->getWriter();

	}

	/**
	 * Does an optimization cycle for each UPDATE event.
	 *
	 * @param string				$event event
	 * @param RedBean_OODBBean $bean	 bean
	 *
	 * @return void
	 */
	public function onEvent( $event , $bean ) {
		try {
			if ($event=="update") {
				$arr = $bean->export();
				unset($arr["id"]);
				if (count($arr)==0) return;
				$table = $this->adapter->escape($bean->getMeta("type"));
				$columns = array_keys($arr);
				//Select a random column for optimization.
				$column = $this->adapter->escape($columns[ array_rand($columns) ]);
				$value = $arr[$column];
				$type = $this->writer->scanType($value);
				$fields = $this->writer->getColumns($table);
				if (!in_array($column,array_keys($fields))) return;
				$typeInField = $this->writer->code($fields[$column]);
				//Specified Columns need to be verified, might be optimized already
				if ($typeInField == RedBean_QueryWriter_MySQL::C_DATATYPE_SPECIFIED) {
					//If type is SPECIFIED and value is SPECIFIED
					if ($fields[$column]=="datetime" && $this->matchesDateTime($value)) return;
				}
				//Is the type too wide?
				if ($type < $typeInField) {
					//echo "\n\n $type < $typeInField ";
					try {
						@$this->adapter->exec("alter table ".$this->writer->noKW($table)." drop __test");
					}catch(Exception $e) {

					}
					//Try to re-fit the entire column; by testing it.
					$type = $this->writer->typeno_sqltype[$type];
					//Add a test column.
					@$this->adapter->exec("alter table ".$this->writer->noKW($table)." add __test ".$type);
					//Copy the values and see if there are differences.
					@$this->adapter->exec("update ".$this->writer->noKW($table)." set __test=".$this->writer->noKW($column)."");
					$rows = $this->adapter->get("select ".$this->writer->noKW($column)." as a, __test as b from ".$this->writer->noKW($table));
					$diff = 0;
					foreach($rows as $row) {
						$diff += ($row["a"]!=$row["b"]);
					}
					if (!$diff) {
						//No differences; shrink column.
						@$this->adapter->exec("alter table ".$this->writer->noKW($table)." change ".$this->writer->noKW($column)." ".$this->writer->noKW($column)." ".$type);
					}
					//Throw away test column; we don't need it anymore!
					@$this->adapter->exec("alter table ".$this->writer->noKW($table)." drop __test");
				}
				else {
					$this->MySQLSpecificColumns($table, $column, $fields[$column], $value);
				}

			}
		}catch(RedBean_Exception_SQL $e) {
			//optimizer might make mistakes, don't care.
			//echo $e->getMessage()."<br>";
		}
	}


	/**
	 * Tries to convert columns to MySQL specific types like:
	 * datetime, ENUM etc. This method is called automatically for you and
	 * works completely in the background. You can however if you like trigger
	 * this method by invoking it directly.
	 *
	 * @param string $table		  table
	 * @param string $column	  column
	 * @param string $columnType type of column
	 * @param string $value		  value
	 *
	 * @return void
	 */
	public function MySQLSpecificColumns( $table, $column, $columnType, $value ) {



		//$this->adapter->getDatabase()->setDebugMode(1);
		$table = $this->adapter->escape($table);
		$column = $this->adapter->escape($column);

		foreach($this->optimizers as $optimizer) {
			if ($optimizer->match($value)) {

			}
		}

		//Is column already datetime?
		if ($columnType!="datetime") {
			if ($this->matchesDateTime($value)) {
				//Ok, value is datetime, can we convert the column to support this?
				$cnt = (int) $this->adapter->getCell("select count(*) as n from $table where
						  $column regexp '[0-9]{4}-[0-1][0-9]-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]'
						  ");
				$total = (int) $this->adapter->getCell("SELECT count(*) FROM ".$this->writer->noKW($table));
				//Is it safe to convert: ie are all values compatible?
				if ($total===$cnt) { //yes
					$this->adapter->exec("ALTER TABLE ".$this->writer->noKW($table)." change ".$this->writer->noKW($column)." ".$this->writer->noKW($column)." datetime ");
				}
			}
		}

	}

	/**
	 * MatchesDateTime matches a value to determine whether it matches the
	 * MySQL datetime type.
	 *
	 * @param string $value Value to match
	 *
	 * @return boolean $yesNo Whether it is a datetime value
	 */
	public function matchesDateTime($value) {
		$pattern = "/^([0-9]{2,4})-([0-1][0-9])-([0-3][0-9]) (?:([0-2][0-9]):([0-5][0-9]):([0-5][0-9]))?$/";
		return (boolean) (preg_match($pattern, $value));
	}


	public function addOptimizer(IOptimizer $optimizer) {

	}

	public function callOptimizer( $optimizer, $call, $arguments ) {

		$method = $toolbox->getDatabaseAdapter()->
		return call_user_func(array($optimizer,$method), $arguments);
	}

}