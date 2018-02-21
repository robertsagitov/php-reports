<?php
class MysqlReportType extends ReportTypeBase {
	//run after parsing all the headers
	//this should do validation that the report is able to run in the current environment
	//it should also handle Included Reports
	public static function init(&$report) {
		//make sure there is SQLite connection info defined for the current environment
		if(!isset(PhpReports::$config['environments'][$report->options['Environment']][$report->options['Database']])) {
			throw new Exception("No ".$report->options['Database']." info defined for environment '".$report->options['Environment']."'");
		}

		//if there are any included reports, add the includedreport sql to the top
		if(isset($report->options['Includes'])) {
			$included_sql = '';
			foreach($report->options['Includes'] as &$included_report) {
				$included_sql .= trim($included_report->raw_query)."\n";
			}

			$report->raw_query = $included_sql . $report->raw_query;
		}
	}

	//called before running the report
	public static function openConnection(&$report) {
		if(isset($report->conn)) return;

		//get the connection info for the current environment
		$environments = PhpReports::$config['environments'];
		$config = $environments[$report->options['Environment']][$report->options['Database']];

		//store the database connection in the report object
		$error = null;
		if(!($report->conn = mysqli_connect($config['host'], $config['user'], $config['pass']))) {
			$error = mysqli_error();
			throw new Exception('Could not connect to MySQL: '.$error);
		} else {
			mysqli_select_db($report->conn, $config['database']);
		}
	}

	//called after running the report
	public static function closeConnection(&$report) {
		if(!isset($report->conn)) return;
		mysqli_close($report->conn);
		unset($report->conn);
	}

	public static function getVariableOptions($params, &$report) {
		$displayColumn = $params['column'];
		if(isset($params['display'])) $displayColumn = $params['display'];

		$query = 'SELECT DISTINCT `'.$params['column'].'` as val, `'.$displayColumn.'` as disp FROM '.$params['table'];

		if(isset($params['where'])) {
			$query .= ' WHERE '.$params['where'];
		}

		if(isset($params['order']) && in_array($params['order'], array('ASC', 'DESC')) ) {
			$query .= ' ORDER BY '.$params['column'].' '.$params['order'];
		}

		//throw new Exception("Query = ".$query);

		$result = mysqli_query($report->conn, $query);
		/*
		if(!$result) {
			$error = mysqli_error();
			throw new Exception("Query failed: ".$error." ; query = ".$query);
		}
		 */

		$options = array();

		if(isset($params['all'])) $options[] = 'ALL';

		while($row = mysqli_fetch_array($result)) {
			$options[] = array(
				'value'=>$row['val'],
				'display'=>$row['disp']
			);
		}
		mysqli_free_result($result);

		return $options;
	}

	//actually runs the report
	public static function run(&$report) {
		$rows = array();

		//expand macros in query using Twig
		$sql = PhpReports::render($report->raw_query, $report->macros);

		//store the original query and formatted query in the report object
		//this is used to display debugging info if there is an error
		$report->options['Query'] = $sql;
		$report->options['Query_Formatted'] = SqlFormatter::highlight($sql);

		//a report can have multiple queries separated by semi-colons
		//split into individual queries and run each one, saving the last result
		$queries = SqlFormatter::splitQuery($sql);

		foreach($queries as $query) {
			//skip empty queries
			$query = trim($query);
			if(!$query) continue;

			$error = null;
			//$result = sqlite_exec($report->conn, $query, $error);
			$result = mysqli_query($report->conn, $query);

			if(!$result) {
				$error = mysqli_error();
				throw new Exception("Query failed: ".$error);
			}
		}

		//fetch the rows as associative arrays
		while($row = mysqli_fetch_array($result,MYSQLI_ASSOC)) {
			$rows[] = $row;
		}
		mysqli_free_result($result);

		return $rows;
	}
}

?>