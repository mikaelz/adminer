<?php
$table_status = table_status($_GET["select"]);
$indexes = indexes($_GET["select"]);
$fields = fields($_GET["select"]);
$rights = array(); // privilege => 0
$columns = array(); // selectable columns
unset($text_length);
foreach ($fields as $key => $field) {
	$name = $adminer->fieldName($field);
	if (isset($field["privileges"]["select"]) && strlen($name)) {
		$columns[$key] = html_entity_decode(strip_tags($name));
		if (ereg('text|blob', $field["type"])) {
			$text_length = $adminer->selectLengthProcess();
		}
	}
	$rights += $field["privileges"];
}

function apply_sql_function($function, $column) {
	return ($function
		? ($function == "distinct" ? "COUNT(DISTINCT " : strtoupper("$function(")) . "$column)"
		: $column
	);
}

list($select, $group) = $adminer->selectColumnsProcess($columns, $indexes);
$where = $adminer->selectSearchProcess($indexes, $fields);
$order = $adminer->selectOrderProcess($columns, $select, $indexes);
$limit = $adminer->selectLimitProcess();
$from = ($select ? implode(", ", $select) : "*") . " FROM " . idf_escape($_GET["select"]) . ($where ? " WHERE " . implode(" AND ", $where) : "");
$group_by = ($group && count($group) < count($select) ? " GROUP BY " . implode(", ", $group) : "") . ($order ? " ORDER BY " . implode(", ", $order) : "");

if ($_POST && !$error) {
	$where_check = "(" . implode(") OR (", array_map('where_check', (array) $_POST["check"])) . ")";
	$primary = null; // empty array means that all primary fields are selected
	foreach ($indexes as $index) {
		if ($index["type"] == "PRIMARY") {
			$primary = ($select ? array_flip($index["columns"]) : array());
		}
	}
	foreach ($select as $key => $val) {
		$val = $_GET["columns"][$key];
		if (!$val["fun"]) {
			unset($primary[$val["col"]]);
		}
	}
	if ($_POST["export"]) {
		dump_headers($_GET["select"]);
		dump_table($_GET["select"], "");
		if (!is_array($_POST["check"]) || $primary === array()) {
			dump_data($_GET["select"], "INSERT", "SELECT $from" . (is_array($_POST["check"]) ? ($where ? " AND " : " WHERE ") . "($where_check)" : "") . $group_by);
		} else {
			$union = array();
			foreach ($_POST["check"] as $val) {
				// where is not unique so OR can't be used
				$union[] = "(SELECT $from " . ($where ? "AND " : "WHERE ") . where_check($val) . $group_by . " LIMIT 1)";
			}
			dump_data($_GET["select"], "INSERT", implode(" UNION ALL ", $union));
		}
		exit;
	}
	if (!$adminer->selectExtraProcess($where)) {
		if (!$_POST["import"]) { // edit
			$result = true;
			$affected = 0;
			$command = ($_POST["delete"] ? ($_POST["all"] && !$where ? "TRUNCATE " : "DELETE FROM ") : ($_POST["clone"] ? "INSERT INTO " : "UPDATE ")) . idf_escape($_GET["select"]);
			if (!$_POST["delete"]) {
				$set = array();
				foreach ($columns as $name => $val) { //! should check also for edit or insert privileges
					$val = process_input($fields[$name]);
					if ($_POST["clone"]) {
						$set[] = ($val !== false ? $val : idf_escape($name));
					} elseif ($val !== false) {
						$set[] = idf_escape($name) . " = $val";
					}
				}
				$command .= ($_POST["clone"] ? "\nSELECT " . implode(", ", $set) . "\nFROM " . idf_escape($_GET["select"]) : " SET\n" . implode(",\n", $set));
			}
			if ($_POST["delete"] || $set) {
				if ($_POST["all"] || ($primary === array() && $_POST["check"])) {
					$result = queries($command . ($_POST["all"] ? ($where ? "\nWHERE " . implode(" AND ", $where) : "") : "\nWHERE $where_check"));
					$affected = $dbh->affected_rows;
				} else {
					foreach ((array) $_POST["check"] as $val) {
						// where is not unique so OR can't be used
						$result = queries($command . "\nWHERE " . where_check($val) . "\nLIMIT 1");
						if (!$result) {
							break;
						}
						$affected += $dbh->affected_rows;
					}
				}
			}
			query_redirect(queries(), remove_from_uri("page"), lang('%d item(s) have been affected.', $affected), $result, false, !$result);
			//! display edit page in case of an error
		} elseif (is_string($file = get_file("csv_file"))) {
			$file = preg_replace("~^\xEF\xBB\xBF~", '', $file); //! character set
			$cols = "";
			$rows = array(); //! packet size
			preg_match_all('~("[^"]*"|[^"\\n]+)+~', $file, $matches);
			foreach ($matches[0] as $key => $val) {
				$row = array();
				preg_match_all('~(("[^"]*")+|[^,]*),~', "$val,", $matches2);
				if (!$key && !array_diff($matches2[1], array_keys($fields))) { //! doesn't work with column names containing ",\n
					// first row corresponds to column names - use it for table structure
					$cols = " (" . implode(", ", array_map('idf_escape', $matches2[1])) . ")";
				} else {
					foreach ($matches2[1] as $col) {
						$row[] = (!strlen($col) ? "NULL" : $dbh->quote(str_replace('""', '"', preg_replace('~^"|"$~', '', $col))));
					}
					$rows[] = "\n(" . implode(", ", $row) . ")";
				}
			}
			$result = queries("INSERT INTO " . idf_escape($_GET["select"]) . "$cols VALUES" . implode(",", $rows));
			query_redirect(queries(), remove_from_uri("page"), lang('%d row(s) has been imported.', $dbh->affected_rows), $result, false, !$result);
		} else {
			$error = upload_error($file);
		}
	}
}

page_header(lang('Select') . ": " . $adminer->tableName($table_status), $error);

echo "<p>";
if (isset($rights["insert"])) {
	//! pass search values forth and back
	echo '<a href="' . htmlspecialchars($SELF) . 'edit=' . urlencode($_GET['select']) . '">' . lang('New item') . '</a> ';
}
echo $adminer->selectLinks($table_status);

if (!$columns) {
	echo "<p class='error'>" . lang('Unable to select the table') . ($fields ? "" : ": " . htmlspecialchars($dbh->error)) . ".\n";
} else {
	echo "<form action='' id='form'>\n";
	echo "<div style='display: none;'>";
	echo (strlen($_GET["server"]) ? '<input type="hidden" name="server" value="' . htmlspecialchars($_GET["server"]) . '">' : "");
	echo '<input type="hidden" name="db" value="' . htmlspecialchars($_GET["db"]) . '">';
	echo '<input type="hidden" name="select" value="' . htmlspecialchars($_GET["select"]) . '">';
	echo "</div>\n";
	$adminer->selectColumnsPrint($select, $columns);
	$adminer->selectSearchPrint($where, $columns, $indexes);
	$adminer->selectOrderPrint($order, $columns, $indexes);
	$adminer->selectLimitPrint($limit);
	$adminer->selectLengthPrint($text_length);
	$adminer->selectActionPrint($text_length);
	echo "</form>\n";
	
	$query = "SELECT " . (intval($limit) && count($group) < count($select) ? "SQL_CALC_FOUND_ROWS " : "") . $from . $group_by . (strlen($limit) ? " LIMIT " . intval($limit) . (intval($_GET["page"]) ? " OFFSET " . ($limit * $_GET["page"]) : "") : "");
	echo $adminer->selectQuery($query);
	
	$result = $dbh->query($query);
	if (!$result) {
		echo "<p class='error'>" . htmlspecialchars($dbh->error) . "\n";
	} else {
		$email_fields = array();
		echo "<form action='' method='post' enctype='multipart/form-data'>\n";
		if (!$result->num_rows) {
			echo "<p class='message'>" . lang('No rows.') . "\n";
		} else {
			$rows = array();
			while ($row = $result->fetch_assoc()) {
				$rows[] = $row;
			}
			$result->free();
			// use count($rows) without LIMIT, COUNT(*) without grouping, FOUND_ROWS otherwise (slowest)
			$found_rows = (intval($limit) && count($group) < count($select)
				? $dbh->result($dbh->query(" SELECT FOUND_ROWS()")) // space to allow mysql.trace_mode
				: count($rows)
			);
			
			$foreign_keys = column_foreign_keys($_GET["select"]);
			$descriptions = $adminer->rowDescriptions($rows, $foreign_keys);
			
			$backward_keys = $adminer->backwardKeys($_GET["select"]);
			$table_names = array_keys($backward_keys);
			if ($table_names) {
				$table_names = array_combine($table_names, array_map(array($adminer, 'tableName'), array_map('table_status', $table_names)));
			}
			
			echo "<table cellspacing='0' class='nowrap'>\n";
			echo "<thead><tr><td><input type='checkbox' id='all-page' onclick='form_check(this, /check/);'>";
			$names = array();
			reset($select);
			foreach ($rows[0] as $key => $val) {
				$val = $_GET["columns"][key($select)];
				$field = $fields[$select ? $val["col"] : $key];
				$name = ($field ? $adminer->fieldName($field) : "*");
				if (strlen($name)) {
					$names[$key] = $name;
					echo '<th><a href="' . htmlspecialchars(remove_from_uri('(order|desc)[^=]*') . '&order%5B0%5D=' . urlencode($key) . ($_GET["order"] == array($key) && !$_GET["desc"][0] ? '&desc%5B0%5D=1' : '')) . '">' . apply_sql_function($val["fun"], $name) . "</a>";
				}
				next($select);
			}
			echo ($backward_keys ? "<th>" . lang('Relations') : "") . "</thead>\n";
			foreach ($descriptions as $n => $row) {
				$unique_idf = implode('&amp;', unique_idf($rows[$n], $indexes)); //! don't use aggregation functions
				echo '<tr' . odd() . '><td><input type="checkbox" name="check[]" value="' . $unique_idf . '" onclick="this.form[\'all\'].checked = false; form_uncheck(\'all-page\');">' . (count($select) != count($group) || information_schema($_GET["db"]) ? '' : ' <a href="' . htmlspecialchars($SELF) . 'edit=' . urlencode($_GET['select']) . '&amp;' . $unique_idf . '">' . lang('edit') . '</a>');
				foreach ($row as $key => $val) {
					if (isset($names[$key])) {
						if (strlen($val) && (!isset($email_fields[$key]) || strlen($email_fields[$key]))) {
							$email_fields[$key] = (is_email($val) ? $names[$key] : ""); //! filled e-mails may be contained on other pages
						}
						$link = "";
						$val = $adminer->editVal($val, $fields[$key]);
						if (!isset($val)) {
							$val = "<i>NULL</i>";
						} else {
							if (ereg('blob|binary', $fields[$key]["type"]) && strlen($val)) {
								$link = htmlspecialchars($SELF . 'download=' . urlencode($_GET["select"]) . '&field=' . urlencode($key) . '&') . $unique_idf;
							}
							if (!strlen(trim($val, " \t"))) {
								$val = "&nbsp;";
							} elseif (strlen($text_length) && ereg('blob|text', $fields[$key]["type"]) && is_utf8($val)) {
								$val = nl2br(shorten_utf8($val, max(0, intval($text_length)))); // usage of LEFT() would reduce traffic but complicate query
							} else {
								$val = nl2br(htmlspecialchars($val));
							}
							
							// link related items
							foreach ((array) $foreign_keys[$key] as $foreign_key) {
								if (count($foreign_keys[$key]) == 1 || count($foreign_key["source"]) == 1) {
									foreach ($foreign_key["source"] as $i => $source) {
										$link .= where_link($i, $foreign_key["target"][$i], $rows[$n][$source]);
									}
									$link = htmlspecialchars((strlen($foreign_key["db"]) ? preg_replace('~([?&]db=)[^&]+~', '\\1' . urlencode($foreign_key["db"]), $SELF) : $SELF) . 'select=' . urlencode($foreign_key["table"])) . $link; // InnoDB supports non-UNIQUE keys
									break;
								}
							}
						}
						if (!$link && is_email($val)) {
							$link = "mailto:$val";
						}
						$val = $adminer->selectVal($val, $link, $fields[$key]);
						echo "<td>$val";
					}
				}
				if ($backward_keys) {
					echo "<td>";
					foreach ($backward_keys as $table => $keys) {
						foreach ($keys as $columns) {
							echo ' <a href="' . htmlspecialchars($SELF) . 'select=' . urlencode($table);
							$i = 0;
							foreach ($columns as $column => $val) {
								echo where_link($i, $column, $rows[$n][$val]);
								$i++;
							}
							echo "\">$table_names[$table]</a>";
						}
					}
				}
				echo "\n";
			}
			echo "</table>\n";
			
			if (intval($limit) && count($group) >= count($select)) {
				// slow with big tables
				ob_flush();
				flush();
				$found_rows = $dbh->result($dbh->query("SELECT COUNT(*) FROM " . idf_escape($_GET["select"]) . ($where ? " WHERE " . implode(" AND ", $where) : "")));
			}
			echo "<p>";
			if (intval($limit) && $found_rows > $limit) {
				// display first, previous 3, next 3 and last page
				$max_page = floor(($found_rows - 1) / $limit);
				echo lang('Page') . ":" . pagination(0) . ($_GET["page"] > 3 ? " ..." : "");
				for ($i = max(1, $_GET["page"] - 2); $i < min($max_page, $_GET["page"] + 3); $i++) {
					echo pagination($i);
				}
				echo ($_GET["page"] + 3 < $max_page ? " ..." : "") . pagination($max_page);
			}
			echo " (" . lang('%d row(s)', $found_rows) . ') <label><input type="checkbox" name="all" value="1">' . lang('whole result') . "</label>\n";
			
			echo (information_schema($_GET["db"]) ? "" : "<fieldset><legend>" . lang('Edit') . "</legend><div><input type='submit' name='edit' value='" . lang('Edit') . "'> <input type='submit' name='clone' value='" . lang('Clone') . "'> <input type='submit' name='delete' value='" . lang('Delete') . "'$confirm></div></fieldset>\n");
			echo "<fieldset><legend>" . lang('Export') . "</legend><div>$dump_output $dump_format <input type='submit' name='export' value='" . lang('Export') . "'></div></fieldset>\n";
		}
		echo "<fieldset><legend>" . lang('CSV Import') . "</legend><div><input type='hidden' name='token' value='$token'><input type='file' name='csv_file'> <input type='submit' name='import' value='" . lang('Import') . "'></div></fieldset>\n";
		
		$adminer->selectExtraPrint(array_filter($email_fields, 'strlen'));
		
		echo "</form>\n";
	}
}
