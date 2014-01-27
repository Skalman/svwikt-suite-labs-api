<?php
/** @modified 2009-08-26 (Dan) */

function sql_encode($str) {
	if (is_array($str)) {
		foreach ($str as $key => $val) {
			$str[$key] = sql_encode($val);
		}
		return $str;
	} else {
		if ($str === null) {
			return null;
		}
		global $g_mysql;
		return $g_mysql->real_escape_string($str);
	}
}

/**
 * Process a single query.
 * @TODO borde kanske returnera array() om inget resultat kom, men det blev korrekt?
 * Verifierat: Returnerar array om resultatet är tomt!
 */
function mysqli_query_to_array(MySQLi $mysql, $query, $key = null) {
	$result = $mysql->query($query);
	if (is_bool($result)) {
		return $result;
	} else {
		$data = array();
		if ($key === null) {
			while ($row = $result->fetch_assoc()) {
				$data[] = &$row;
				unset($row);
			}
		} else {
			while ($row = $result->fetch_assoc()) {
				$data[$row[$key]] = &$row;
				unset($row);
			}
		}
		$result->free();
		return $data;
	}
}
function mysql_query_to_array($query, $link = null, $key = null) {
	if ($link === null) {
		$result = mysql_query($query);
	} else {
		$result = mysql_query($query, $link);
	}
	if (is_bool($result))
		return $result;
	$data = array();
	if ($key === null) {
		while ($row = mysql_fetch_assoc($result)) {
			$data[] = &$row;
			unset($row);
		}
	} else {
		while ($row = mysql_fetch_assoc($result)) {
			$data[$row[$key]] = &$row;
			unset($row);
		}
	}
	mysql_free_result($result);
	return $data;
}

/**
 * Returns the values of the row of the result (or false on failure and
 * true if no result was returned.
 */
function mysqli_query_first_row(MySQLi $mysql, $query) {
	$result = $mysql->query($query);
	if ($result) {
		$first_row = $result->fetch_array(MYSQLI_ASSOC);
		$result->free();
		return $first_row;
	} else {
		return (bool) $mysql->errno;
	}
}
function mysql_query_first_row($query, $link = null) {
	if ($link === null) {
		$result = mysql_query($query);
	} else {
		$result = mysql_query($query, $link);
	}
	if (is_bool($result))
		return $result;
	else
		return mysql_fetch_assoc($result);
}

/**
 * Returns the value of the first cell of the result (or false on failure and
 * true if no result was returned.
 */
function mysqli_query_first_cell(MySQLi $mysql, $query) {
	$result = $mysql->query($query);
	if ($result) {
		$first_row = $result->fetch_array(MYSQLI_NUM);
		$result->free();
		return $first_row[0];
	} else {
		return (bool) $mysql->errno;
	}
}
function mysql_query_first_cell($query, $link = null) {
	if ($link === null) {
		$result = mysql_query($query);
	} else {
		$result = mysql_query($query, $link);
	}
	if (is_bool($result))
		return $result;
	else
		return @mysql_result($result, 0); // TODO kolla att det här funkar optimalt
}


/**
 * Processes multiple queries. All queries will be executed, even if one fails.
 * Preferably one query per array item, otherwise expect unexpected results. End
 * each query with or without a ";", but nothing else.
 */
function mysqli_multiple_queries_to_array(MySQLi $mysql, $queries, &$errors = null) {
	$num_queries = count($queries);
	$errors = array();
	foreach ($queries as $key => $query) {
		$queries[$key] = trim($query, " \t\n;") . ";\n";
		$errors[] = array(0, '');
	}
	$data = mysqli_multiquery_to_array($mysql, implode($queries));
	$i = 0;
	while ($num_queries > $queries_done = count($data)) {
		$errors[$queries_done - 1] = array($mysql->errno, $mysql->error);
		for (; $i < $queries_done; $i++) {
			unset($queries[$i]);
		}
		$data = array_merge($data, mysqli_multiquery_to_array($mysql, implode($queries)));
	}
	return $data;
}
function mysql_multiple_queries_to_array(&$queries, $link = null, &$errors = null) {
	$data = array();
	$errors = array();
	foreach ($queries as $query) {
		$result = mysql_query($query, $link);
		$errors[] = array(mysql_errno(), mysql_error());
		if (is_bool($result)) {
			$data[] = $result;
		} else {
			$tmpData = array();
			while ($row = mysql_fetch_assoc($result)) {
				$tmpData[] = &$row;
			}
			mysql_free_result($result);
			$data[] = &$tmpData;
			unset($tmpData);
		}
	}
	return $data;
}

/** Processes multiple queries. If one query fails subsequent queries will not be executed. */
function mysqli_multiquery_to_array(MySQLi $mysql, $query, $elements_in_array = null) {
	$t = $mysql->multi_query($query);
	$data = array();
	do {
		$result = $mysql->store_result();
		if (!$result) {
			/*
			 * No result because of error or it's a statement, but $mysql->next_result()
			 * returns false if it's an error, so now it must be a statement.
			 */
			$data[] = true;
		} else {
			$tmpData = array();
			while ($row = $result->fetch_assoc()) {
				$tmpData[] = $row;
			}
			$result->free();
			$data[] = $tmpData;
		}
	} while ($mysql->next_result());
	
	if ($mysql->errno) {
		$data[] = false;
		if ($elements_in_array !== null) {
			for ($i = count($data); $i < $elements_in_array; $i++) {
				$data[] = false;
			}
		}
	}
	return $data;
}
