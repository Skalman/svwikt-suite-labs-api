<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

// output(get_page_inflections(@$_GET['title']));

require 'mysql.php';

function output($var) {
	header('Content-Type: application/json');
	echo json_encode($var);
	exit;
}


function error($message) {
	output(array(
		'status' => 'error',
		'error_message' => $message,
	));
}

$cnf = parse_ini_file('/data/project/svwiktionary/replica.my.cnf');
$inflection_db = 'p50380g50552__inflections';
// tables: page, template_use, inflection

function escape_to_list($m, $array) {
	return implode(', ', array_map(function ($value) use ($m) {
			return "'" . $m->real_escape_string($value) . "'";
		}, $array));
}

if (@$_REQUEST['action'] === 'get_inflections') {
	require 'get_inflections.php';
} elseif (@$_REQUEST['action'] === 'get_changes') {
	require 'get_changes.php';
} else {
	output(array(
		'status' => 'error',
		'error' => true,
		'error_message' => "Expected parameter 'action' to be 'get_inflections' or 'get_changes'.",
	));
}
