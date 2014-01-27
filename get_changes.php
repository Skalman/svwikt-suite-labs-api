<?php

if (!isset($_REQUEST['since'])) {
	error("Expected parameter 'since'.");
}
$since = $_REQUEST['since'];

if (preg_match('/[^0-9]/', $since)) {
	error("Expected parameter 'since' to contain only numbers.");
}

date_default_timezone_set('UTC');
if (":$since" < date(':YmdHis', time() - 60*60*24*90)) {
	error("Cannot get records older than 90 days. Use a dump.");
}

$limit = null;
if (@$_REQUEST['limit']) {
	$limit = min((int)$_REQUEST['limit'], 500);	
}
if (!$limit) {
	$limit = 10;
}

$m = new MySQLi(
	'svwiktionary.labsdb',
	$cnf['user'],
	$cnf['listor_password'],
	'svwiktionary_p');

if (@$_REQUEST['continue']) {
	$continue_sql = $m->real_escape_string($_REQUEST['continue']);
	$continue_sql = explode(':', $continue_sql, 2);
	$condition = "page_touched > '$since' OR (page_touched = '$since' AND (
			page_namespace > '$continue_sql[0]' OR (page_namespace = $continue_sql[0] AND
				page_title > '$continue_sql[1]'
			)
		))";
} else {
	$condition = "page_touched >= '$since'";
}

$edits = mysqli_query_to_array($m,
	$q="SELECT page_title, page_namespace, page_touched
	FROM page
	WHERE $condition
	ORDER BY page_touched ASC, page_namespace, page_title
	LIMIT $limit;");
if ($edits === false) error(array('edits', $m->error, $q));

$until = null;
$continue = null;

if (count($edits) === $limit) {
	$last = $edits[count($edits)-1];
	$until = $last['page_touched'];
	$continue = array(
		'since' => $until,
		'continue' => "$last[page_namespace]:$last[page_title]",
	);
}

$edited_titles = array();

foreach ($edits as $edit) {
	$edited_titles[$edit['page_namespace']][$edit['page_title']] = true;
}


$since_condition = !@$_REQUEST['continue']
	? "'$since' <= log_timestamp AND"
	: "'$since' < log_timestamp AND"; // on a continued query, the previous response contained these deletions
$until_condition = $until
	? "log_timestamp <= '$until' AND"
	: '';

$deletions = mysqli_query_to_array($m,
	$q="SELECT
		log_title,
		log_namespace,
		log_params,
		log_type,
		MAX(log_timestamp) AS log_timestamp
	FROM logging
	WHERE
		$since_condition
		$until_condition
		(log_type = 'move' OR log_type = 'delete')

	GROUP BY log_title
	ORDER BY log_timestamp ASC;");
if ($deletions === false) error(array('deletions', $m->error, $q));

$actual_deletions = array();
foreach ($deletions as $key => $deletion) {
	if (isset($edited_titles[$deletion['log_namespace']][$deletion['log_title']]))
		continue;

	if ($deletion['log_type'] === 'move') {
		$params = unserialize($deletion['log_params']);
		if (@$params['5::noredir'] !== '1') {
			continue;
		}
	}

	$actual_deletions[] = array(
		'page_title' => $deletion['log_title'],
		'page_namespace' => $deletion['log_namespace'],
		'log_timestamp' => $deletion['log_timestamp'],
	);
}


output(array(
	'status' => 'success',
	'since' => $since,
	'continue' => $continue,
	'changes' => array(
		'edits' => $edits,
		'deletions' => $actual_deletions,
	),
));

