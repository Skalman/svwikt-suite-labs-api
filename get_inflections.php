<?php

function profile_start() {
	global $profiling;
	$profiling = microtime(true);
}

function profile_end() {
	global $profiling;
	return microtime(true) - $profiling;
}

$debug = array();

if (!isset($_REQUEST['titles'])) {
	output(array(
		'status' => 'error',
		'error_message' => "Expected parameter 'titles'.",
	));
}
$titles = explode('|', @$_REQUEST['titles']);

$m = new MySQLi(
	'svwiktionary.labsdb',
	$cnf['user'],
	$cnf['password'],
	$inflection_db);

$titles_sql = escape_to_list($m, $titles);

profile_start();
$prep = mysqli_query_to_array($m,
	"SELECT
		page.page_title,
		page.page_touched,

		IF(cached.page_title IS NOT NULL AND page.page_touched != cached.page_touched,
			1, 0) AS to_delete,
		IF(tl.tl_title IS NOT NULL AND (cached.page_title IS NULL OR page.page_touched != cached.page_touched),
			1, 0) AS to_parse_and_insert,
		IF(page.page_touched = cached.page_touched, -- AND tl.tl_title IS NOT NULL
			1, 0) AS to_get_from_table,
		IF(tl.tl_title IS NOT NULL,
			1, 0) AS has_inflections

	FROM svwiktionary_p.page AS page
	LEFT JOIN svwiktionary_p.templatelinks AS tl
		ON page.page_id = tl.tl_from AND tl.tl_namespace = 10 AND tl.tl_title = 'grammatik-start'
	LEFT JOIN $inflection_db.page AS cached
		ON page.page_title = cached.page_title
	WHERE page.page_namespace = 0 AND page.page_title IN ($titles_sql)
	LIMIT 10;",
		'page_title');
$debug['timing-prep'] = profile_end();

if (!$prep) output($m->error);

$to_delete = array();
$to_parse_and_insert = array();
$to_get_from_table = array();

$response = array();

foreach ($titles as $title) {
	if (!isset($prep[$title])) {
		$response[$title] = array('missing' => '');
		continue;
	}
	if ($prep[$title]['to_delete'] === '1')
		$to_delete[] = $title;

	if ($prep[$title]['to_parse_and_insert'] === '1')
		$to_parse_and_insert[] = $title;

	if ($prep[$title]['to_get_from_table'] === '1')
		$to_get_from_table[] = $title;

	if ($prep[$title]['has_inflections'] === '0')
		$response[$title] = array(
			'page_touched' => $prep[$title]['page_touched'],
			'templates' => array(),
		);
}

// DELETE
if ($to_delete) {
	$delete_titles_sql = escape_to_list($m, $to_delete);
	$m->query("DELETE FROM $inflection_db.page
		WHERE page.page_title IN ($delete_titles_sql);");
	if ($m->error) output($m->error);
	$response['deleted'] = $to_delete;
}

// PARSE AND INSERT
$m->autocommit(false);
foreach ($to_parse_and_insert as $title) {
	$infl = get_page_inflections($title);
	$infl = $infl['inflections'];
	$response[$title] = array(
		'page_touched' => $prep[$title]['page_touched'],
		'templates' => $infl,
	);

	$m->query(sprintf(
		"INSERT INTO $inflection_db.page (page_title, page_touched)
		VALUES ('%s', '%s');",
		$m->real_escape_string($title),
		$m->real_escape_string($prep[$title]['page_touched'])
	));
	foreach ($infl as $per_template) {
		$m->query(sprintf(
			"INSERT INTO $inflection_db.template_use (page_title, template)
			VALUES ('%s', '%s');",
			$m->real_escape_string($title),
			$m->real_escape_string($per_template['template'])
		));
		$m->query(sprintf(
			"INSERT INTO $inflection_db.inflection (use_id, form, type)
			VALUES %s;",

			implode(",\n", array_map(function ($inflection) use ($m) {
				return sprintf("(LAST_INSERT_ID(), '%s', '%s')",
					$m->real_escape_string($inflection['value']),
					$m->real_escape_string($inflection['type'])
				);
			}, $per_template['inflections']))
		));
	}
	$m->commit();
}
$m->autocommit(true);


// GET FROM TABLE
if ($to_get_from_table) {
	$get_titles_sql = escape_to_list($m, $to_get_from_table);
	profile_start();
	$template_uses = mysqli_query_to_array($m,
		"SELECT
			page_title,
			page_touched,
			template,
			group_concat(form separator '|') AS forms,
			group_concat(type separator '|') AS types
		FROM page
		INNER JOIN template_use USING(page_title)
		INNER JOIN inflection USING(use_id)
		WHERE page_title IN ($get_titles_sql)
		GROUP BY use_id;");
	$debug['timing-get_from_table'] = profile_end();

	foreach ($template_uses as $use) {
		$title = $use['page_title'];
		$response[$title]['page_touched'] = $use['page_touched'];
		$forms = explode('|', $use['forms']);
		$types = explode('|', $use['types']);
		$response[$title]['templates'][] = array(
			'template' => $use['template'],
			'inflections' => array_map(function ($form, $type) {
				return array(
					'value' => $form,
					'type' => $type,
				);
			}, $forms, $types),
		);
	}
}


$debug['to_delete'] = $to_delete;
$debug['to_parse_and_insert'] = $to_parse_and_insert;
$debug['to_get_from_table'] = $to_get_from_table;

output(array(
	'status' => 'success',
	'debug' => $debug,
	'inflections' => $response,
));

function get_page_inflections($title) {
	$html = file_get_contents(
		'https://sv.wiktionary.org/w/api.php?format=json&action=parse&prop=text&page='
		. urlencode($title));
	$html = json_decode($html, true);
	if (@$html['error']['code'] === 'missingtitle') {
		return array(
			'status' => 'error',
			'error_message' => 'Missing title.',
		);
	}
	$html = $html['parse']['text']['*'];

	$doc = new DOMDocument();
	$doc->loadHTML($html);

	$inflections = array();
	$tables = $doc->getElementsByTagName('table');
	foreach ($tables as $table) {
		$table_class = $table->getAttribute('class');
		$template;
		if (!preg_match('/(^| )grammar( |$)/', $table_class))
			continue;
		if (!preg_match('/(^| )template\-([^ ]+)( |$)/', $table_class, $template))
			continue;
		$template = $template[2];
		$template_inflections = array();

		$spans = $table->getElementsByTagName('span');
		foreach ($spans as $span) {
			$span_class = $span->getAttribute('class');
			if (!preg_match('/^(b|adv|prespart|perfpart)\-[^ ]*$/', $span_class))
				continue;

			$strongs = $span->getElementsByTagName('strong');
			foreach ($strongs as $strong) {
				if ($strong->getAttribute('class') !== 'selflink')
					continue;

				$template_inflections[] = array(
					'value' => $title,
					'type' => $span_class,
				);
			}

			$as = $span->getElementsByTagName('a');
			foreach ($as as $a) {
				$href = $a->getAttribute('href');
				$target = null;
				if ($a->getAttribute('class') === 'new') {
					preg_match('/^\/w\/index\.php\?title=([^&]+)&action=edit&redlink=1$/', $href, $target);
				} else {
					preg_match('/^\/wiki\/([^#]+)(#.*)?$/', $href, $target);
				}
				if (!$target)
					continue;

				$template_inflections[] = array(
					'value' => urldecode($target[1]),
					'type' => $span_class,
				);
			}
		}

		$inflections[] = array(
			'template' => $template,
			'inflections' => $template_inflections,
		);
	}

	return array(
		'status' => 'success',
		'inflections' => $inflections,
	);
}
