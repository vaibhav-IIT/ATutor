<?php
/************************************************************************/
/* ATutor																*/
/************************************************************************/
/* Copyright (c) 2002-2004 by Greg Gay, Joel Kronenberg & Heidi Hazelton*/
/* Adaptive Technology Resource Centre / University of Toronto			*/
/* http://atutor.ca														*/
/*																		*/
/* This program is free software. You can redistribute it and/or		*/
/* modify it under the terms of the GNU General Public License			*/
/* as published by the Free Software Foundation.						*/
/************************************************************************/
// $Id$

/**
* TableFactory
* Class for creating AbstractTable Objects
* @access	public
* @author	Joel Kronenberg
* @package	Backup
*/
class TableFactory {
	/**
	* The database handler.
	*
	* @access  private
	* @var resource
	*/
	var $db;

	/**
	* The ATutor version this backup was created with.
	*
	* @access private
	* @var string
	*/
	var $version;

	/**
	* The course ID we're restoring into.
	*
	* @access private
	* @var int
	*/
	var $course_id;

	/**
	* The directory unzip backup is found.
	*
	* @access private
	* @var string
	*/
	var $import_dir;

	/**
	* Constructor.
	* 
	* @param string $version The backup version.
	* @param resource $db The database handler.
	* @param int $course_id The ID of this course.
	* @param string $import_dir The directory where the backup was unzipped to.
	* 
	*/
	function TableFactory ($version, $db, $course_id, $import_dir) {
		$this->version = $version;
		$this->db = $db;
		$this->course_id = $course_id;
		$this->import_dir = $import_dir;
	}

	/**
	* Create and return the specified AbstractTable Object.
	* 
	* @access public
	*
	* @param string $table_name The name of the table to create an Object for.
	*
	* @return AbstractTable Object|NULL if $table_name does not match available Objects.
	*
	* @See AbstractTable
	*
	*/
	function createTable($table_name) {
		static $resource_categories_id_map; // old -> new ID's
		static $content_id_map; // old -> new ID's
		static $tests_id_map; // old -> new ID's

		switch ($table_name) {
			case 'stats':
				return new CourseStatsTable($this->version, $this->db, $this->course_id, $this->import_dir, $garbage);
				break;

			case 'polls':
				return new PollsTable($this->version, $this->db, $this->course_id, $this->import_dir, $garbage);
				break;

			case 'tests':
				return new TestsTable($this->version, $this->db, $this->course_id, $this->import_dir, $tests_id_map);
				break;

			case 'tests_questions':
				return new TestsQuestionsTable($this->version, $this->db, $this->course_id, $this->import_dir, $tests_id_map);
				break;

			case 'news':
				return new NewsTable($this->version, $this->db, $this->course_id, $this->import_dir, $garbage);
				break;

			case 'forums':
				return new ForumsTable($this->version, $this->db, $this->course_id, $this->import_dir, $garbage);
				break;

			case 'glossary':
				return new GlossaryTable($this->version, $this->db, $this->course_id, $this->import_dir, $garbage);
				break;

			case 'resource_links':
				return new ResourceLinksTable($this->version, $this->db, $this->course_id, $this->import_dir, $resource_categories_id_map);
				break;

			case 'resource_categories':
				return new ResourceCategoriesTable($this->version, $this->db, $this->course_id, $this->import_dir, $resource_categories_id_map);
				break;

			case 'content':
				return new ContentTable($this->version, $this->db, $this->course_id, $this->import_dir, $content_id_map);
				break;

			case 'related_content':
				return new RelatedContentTable($this->version, $this->db, $this->course_id, $this->import_dir, $content_id_map);
				break;
			default:
				return NULL;
		}
	}
}

/**
* AbstractTable
* Class for restoring backup tables
* @access	public
* @author	Joel Kronenberg
* @package	Backup
*/
class AbstractTable {
	/**
	* The ATutor version this backup was created with.
	*
	* @access protected
	* @var string
	*/
	var $version;

	/**
	* The database handler.
	*
	* @access  private
	* @var resource
	*/
	var $db;

	/**
	* The CSV table file handler.
	*
	* @access  private
	* @var resource
	*/
	var $fp;

	/**
	* The course ID we're restoring into.
	*
	* @access private
	* @var int
	*/
	var $course_id;

	/**
	* The directory unzip backup is found.
	*
	* @access private
	* @var string
	*/
	var $import_dir;

	/**
	* A hash table associated old ID's (key) with their new ID's (value).
	* Used for the content table where there is a parent ID and a child ID.
	*
	* @access private
	* @var array
	*/
	var $old_id_to_new_id;

	/**
	* A hash table associated old ID's (key) with their new ID's (value).
	* A copy of $old_id_to_new_id but the ID's are keys to a _different_
	* table. Example: The CatID from the resource_categories to CatID
	* in the resource_links table.
	*
	* @access private
	* @var array
	*/
	var $new_parent_ids;


	/**
	* Constructor.
	* 
	* @param string $version The backup version.
	* @param resource $db The database handler.
	* @param int $course_id The ID of this course.
	* @param string $import_dir The directory where the backup was unzipped to.
	* @param array $old_id_to_new_id Reference to either the parent ID's or to store current ID's.
	* 
	*/
	function AbstractTable($version, $db, $course_id, $import_dir, &$old_id_to_new_id) {
		$this->db =& $db;
		$this->course_id = $course_id;
		$this->version = $version;
		$this->import_dir = $import_dir;

		//$this->importDir = 
		if (empty($old_id_to_new_id)) {
			$this->old_id_to_new_id =& $old_id_to_new_id;
		} else {
			$this->new_parent_ids = $old_id_to_new_id;
		}
	}

	// -- public methods below:

	/**
	* Restores the table defined in the CSV file, one row at a time.
	* 
	* @access public
	* @return void
	*
	* @See getRows()
	* @See insertRow()
	*/
	function restore() {
		$this->lockTable();

		$this->getRows();

		if ($this->rows) {
			foreach ($this->rows as $row) {
				$row = $this->convert($row);
				$sql = $this->generateSQL($row); 
				debug($sql);
				mysql_query($sql, $this->db);
				debug(mysql_error($this->db));
			}
		}
		$this->unlockTable();
	}

	// -- protected methods below:

	/**
	* Converts escaped white space characters to their correct representation.
	* 
	* @access protected
	* @param string $input The string to convert.
	* @return string The converted string.
	* @See Backup::quoteCSV()
	*/
	function translateWhitespace($input) {
		$input = str_replace('\n', "\n", $input);
		$input = str_replace('\r', "\r", $input);
		$input = str_replace('\x00', "\0", $input);

		$input = addslashes($input);
		return $input;
	}

	// protected
	// find the index offset
	function findOffset($id) {
		return $this->rows[$id]['index_offset'];
	}

	// -- private methods below:
	function getNextID() {
		$sql      = 'SELECT MAX(' . $this->primaryIDField . ') AS next_id FROM ' . TABLE_PREFIX . $this->tableName;
		$result   = mysql_query($sql, $this->db);
		$next_index = mysql_fetch_assoc($result);
		return ($next_index['next_id'] + 1);
	}

	/**
	* Reads the CSV table file into array $this->rows.
	* 
	* @access private
	* @return void
	*
	* @See openTable()
	* @See closeTable()
	* @See getOldID()
	*/
	function getRows() {
		$this->openFile();
		$i = 0;

		$next_id = $this->getNextID();
		debug('next ID: '. $next_id);

		while ($row = fgetcsv($this->fp, 70000)) {
			if (count($row) < 2) {
				continue;
			}
			$row = $this->translateText($row);
			$row['index_offset'] = $i;
			$row['new_id'] = $next_id++;
			if ($this->getOldID($row) === FALSE) {
				$this->rows[] = $row;
			} else {
				$this->rows[$this->getOldID($row)] = $row;
				$this->old_id_to_new_id[$this->getOldID($row)] = $row['new_id'];
			}

			$i++;
		}
		$this->closeFile();
	}

	/**
	* Converts $row to be ready for inserting into the db.
	* 
	* @param array $row The row to convert.
	* @access private
	* @return array The converted row.
	*
	* @see translateWhitespace()
	*/
	function translateText($row) {
		global $backup_tables;
		$count = 0;
		foreach ($backup_tables[$this->tableName]['fields'] as $field) {
			if ($field[1] == TEXT) {
				$row[$count] = $this->translateWhitespace($row[$count]);
			}
			$count++;
		}
		return $row;
	}

	/**
	* Locks the database table for writing.
	* 
	* @access private
	* @return void
	*
	* @See unlockTable()
	*/
	function lockTable() {
		$lock_sql = 'LOCK TABLES ' . TABLE_PREFIX . $this->tableName. ' WRITE';
		$result   = mysql_query($lock_sql, $this->db);
	}

	/**
	* UnLocks the database table.
	* 
	* @access private
	* @return void
	*
	* @See lockTable()
	*/
	function unlockTable() {
		$lock_sql = 'UNLOCK TABLES';
		$result   = mysql_query($lock_sql, $this->db);
	}

	/**
	* Opens the CSV table file for reading.
	* 
	* @access private
	* @return void
	*
	* @See closeFile()
	*/
	function openFile() {
		$this->fp = fopen($this->import_dir . $this->tableName . '.csv', 'rb');
	}

	/**
	* Closes the CSV table file.
	* 
	* @access private
	* @return void
	*
	* @See openFile()
	*/
	function closeFile() {
		fclose($this->fp);
	}

	/**
	* Gets the entry/row's new ID based on it's old entry ID.
	* 
	* @param int $id The old entry ID.
	* @access protected
	* @return int The new entry ID
	*
	*/
	function getNewID($id) {
		return $this->rows[$id]['new_id'];
	}


	// -- abstract methods below:
	/**
	* Gets the entry/row ID as it appears in the CSV file, or FALSE if n/a.
	* 
	* @param array $row The old entry row from the CSV file.
	* @access private
	* @return boolean|int The old ID or FALSE if not applicable.
	*
	*/
	function getOldID($row)    { /* abstract */ }

	/**
	* Convert the entry/row to the current ATutor version.
	* 
	* @param array $row The old entry row from the CSV file.
	* @access private
	* @return array The converted row.
	*
	*/
	function convert($row)     { /* abstract */ }

	/**
	* Generate the SQL for this table.
	* 
	* Precondition: $row has passed through convert() and 
	* translateText().
	*
	* @param array $row The old entry row from the CSV file.
	* @access private
	* @return string The SQL query.
	*
	* @see insertRow()
	*/
	function generateSQL($row) { /* abstract */ }

}
//---------------------------------------------------------------------

/**
* ForumsTable
* Extends AbstractTable and provides table specific methods and members.
* @access	public
* @author	Joel Kronenberg
* @author	Heidi Hazelton
* @package	Backup
*/
class ForumsTable extends AbstractTable {
	/**
	* The ATutor database table name (w/o prefix).
	* Also the CSV file name (w/o extension).
	*
	* @access private
	* @var const string
	*/
	var $tableName      = 'forums';

	var $primaryIDField = 'forum_id';

	// -- private methods below:
	function getOldID($row) {
		return FALSE;
	}

	function convert($row) {
		// There are no table changes made to the `forums` table.
		// Return the row unchanged.

		return $row;
	}

	function generateSQL($row) {
		$sql = 'INSERT INTO '.TABLE_PREFIX.'forums VALUES ';
		$sql .= '('.$row['new_id']. ',';
		$sql .= $this->course_id . ','; // course_id
		$sql .= "'".$row[0]."',"; // title
		$sql .= "'".$row[1]."',"; // description
		$sql .= "'".$row[2]."',"; // num_topics
		$sql .= "'".$row[3]."',"; // num_posts
		$sql .= "'".$row[4]."')"; // last_post

		return $sql;
	}
}
//---------------------------------------------------------------------
/**
* GlossaryTable
* Extends AbstractTable and provides table specific methods and members.
* @access	public
* @author	Joel Kronenberg
* @author	Heidi Hazelton
* @package	Backup
*/
class GlossaryTable extends AbstractTable {
	var $tableName      = 'glossary';
	var $primaryIDField = 'word_id';

	function getOldID($row) {
		return $row[0];
	}

	// private
	function convert($row) {
		return $row;
	}

	// private
	function generateSQL($row) {
		// insert row
		$sql = 'INSERT INTO '.TABLE_PREFIX.'glossary VALUES ';
		$sql .= '('.$row['new_id'].','; // word_id  
		$sql .= $this->course_id . ',';	   // course_id 
		$sql .= "'".$row[1]."',";		   // word
		$sql .= "'".$row[2]."',";		   // definition
		if ($row[3] == 0) {
			$sql .= 0;
		} else {
			$sql .= $this->getNewID($row[3]); // related word
		}
		$sql .=  ')';

		return $sql;
	}
}
//---------------------------------------------------------------------
/**
* ResourceCategoriesTable
* Extends AbstractTable and provides table specific methods and members.
* @access	public
* @author	Joel Kronenberg
* @author	Heidi Hazelton
* @package	Backup
*/
class ResourceCategoriesTable extends AbstractTable {
	var $tableName = 'resource_categories';

	var $primaryIDField = 'CatID';

	function getOldID($row) {
		return $row[0];
	}

	// private
	function convert($row) {
		return $row;
	}

	// private
	function generateSQL($row) {
		$sql = 'INSERT INTO '.TABLE_PREFIX.'resource_categories VALUES ';
		$sql .= '('.$row['new_id'].',';
		$sql .= $this->course_id .',';

		// CatName
		$sql .= "'".$row[1]."',";

		// CatParent
		if ($row[2] == 0) {
			$sql .= 'NULL';
		} else {
			$sql .= $this->getNewID($row[2]); // category parent
		}
		$sql .= ')';

		return $sql;
	}
}

//---------------------------------------------------------------------
class ResourceLinksTable extends AbstractTable {
	var $tableName = 'resource_links';

	var $primaryIDField = 'LinkID';

	function getOldID($row) {
		return FALSE;
	}

	// private
	function convert($row) {
		// handle the white space issue as well
		return $row;
	}

	// private
	function generateSQL($row) {
		// insert row
		$sql = 'INSERT INTO '.TABLE_PREFIX.'resource_links VALUES ';
		$sql .= '('.$row['new_id'].', ';
		$sql .= $this->new_parent_ids[$row[0]] . ',';

		$sql .= "'".$row[1]."',"; // URL
		$sql .= "'".$row[2]."',"; // LinkName
		$sql .= "'".$row[3]."',"; // Description
		$sql .= $row[4].',';      // Approved
		$sql .= "'".$row[5]."',"; // SubmitName
		$sql .= "'".$row[6]."',"; // SubmitEmail
		$sql .= "'".$row[7]."',"; // SubmitDate
		$sql .= $row[8]. ')';

		return $sql;
	}
}
//---------------------------------------------------------------------
class NewsTable extends AbstractTable {
	var $tableName = 'news';
	var $primaryIDField = 'news_id';

	function getOldID($row) {
		return FALSE;
	}

	// private
	function convert($row) {
		return $row;
	}

	// private
	function generateSQL($row) {
		// insert row
		$sql = 'INSERT INTO '.TABLE_PREFIX.'news VALUES ';
		$sql .= '('.$row['new_id'].',';
		$sql .= $this->course_id.',';
		$sql .= $_SESSION['member_id'].',';
		$sql .= "'".$row[0]."',"; // date
		$sql .= "'".$row[1]."',"; // formatting
		$sql .= "'".$row[2]."',"; // title
		$sql .= "'".$row[3]."')"; // body

		return $sql;
	}
}
//---------------------------------------------------------------------
class TestsTable extends AbstractTable {
	var $tableName = 'tests';
	var $primaryIDField = 'test_id';

	function getOldID($row) {
		return $row[0];
	}

	// private
	function convert($row) {
		// handle the white space issue as well
		if (version_compare($this->version, '1.4', '<')) {
			$row[8] = 0;
			$row[9] = 0;
			$row[10] = 0;
			$row[11] = 0;
		} 
		
		if (version_compare($this->version, '1.4.2', '<')) {
			$row[12] = 0;
			$row[13] = 0;
		}
		return $row;
	}

	// private
	function generateSQL($row) {
		// insert row

		$sql = '';
		$sql = 'INSERT INTO '.TABLE_PREFIX.'tests VALUES ';
		$sql .= '('.$row['new_id'].',';
		$sql .= $this->course_id.',';

		$sql .= "'".$row[1]."',";	//title
		$sql .= "'".$row[2]."',";	//format
		$sql .= "'".$row[3]."',";	//start_date
		$sql .= "'".$row[4]."',";	//end_date
		$sql .= "'".$row[5]."',";	//randomize_order
		$sql .= "'".$row[6]."',";	//num_questions
		$sql .= "'".$row[7]."',";	//instructions
		$sql .= '0,';				//content_id
		$sql .= $row[9] . ',';		//automark
		$sql .= $row[10] . ',';		//random
		$sql .= $row[11] . ',';		//difficulty
		$sql .= $row[12] . ',';		//num_takes
		$sql .= $row[13];			//anonymous
		$sql .= ')';

		return $sql;
	}
}
//---------------------------------------------------------------------
class TestsQuestionsTable extends AbstractTable {
	var $tableName = 'tests_questions';
	var $primaryIDField = 'question_id';

	function getOldID($row) {
		return FALSE;
	}

	// private
	function convert($row) {
		if (version_compare($this->version, '1.4', '<')) {
			$row[28] = 0;
		}	
		return $row;
	}

	// private
	function generateSQL($row) {
		// insert row
		$sql = 'INSERT INTO '.TABLE_PREFIX.'tests_questions VALUES ';
		$sql .= '('.$row['new_id'].',' . $this->new_parent_ids[$row[0]] . ',';
		$sql .= $this->course_id;

		for ($i=1; $i<=28; $i++) {
			$sql .= ",'".$row[$i]."'";
		}

		$sql .= ')';

		return $sql;
	}
}
//---------------------------------------------------------------------
class PollsTable extends AbstractTable {
	var $tableName = 'polls';
	var $primaryIDField = 'poll_id';

	function getOldID($row) {
		return FALSE;
	}

	// private
	function convert($row) {
		return $row;
	}

	// private
	function generateSQL($row) {
		// insert row
		$sql = 'INSERT INTO '.TABLE_PREFIX.'polls VALUES ';
		$sql .= '('.$row['new_id'].',';
		$sql .= $this->course_id.',';
		$sql .= "'$row[0]',"; // question
		$sql .= "'$row[1]',"; // created date
		$sql .= "0,";         // total

		for ($i=2; $i<=8; $i++) {
			$sql .= "'".$row[$i]."',0,";
		}

		$sql  = substr($sql, 0, -1);
		$sql .= ')';

		return $sql;
	}
}
//---------------------------------------------------------------------
class ContentTable extends AbstractTable {
	var $tableName = 'content';

	var $primaryIDField = 'content_id';

	var $ordering;

	/**
	* Constructor.
	* 
	* @param string $version The backup version.
	* @param resource $db The database handler.
	* @param int $course_id The ID of this course.
	* @param string $import_dir The directory where the backup was unzipped to.
	* @param array $old_id_to_new_id Reference to either the parent ID's or to store current ID's.
	* 
	*/
	function ContentTable($version, $db, $course_id, $import_dir, &$old_id_to_new_id) {
		// special case for `content` -- we need the max ordering

		$sql	    = 'SELECT MAX(ordering) AS ordering FROM '.TABLE_PREFIX.'content WHERE content_parent_id=0 AND course_id='.$course_id;
		$result     = mysql_query($sql, $db);
		$ordering   = mysql_fetch_assoc($result);
		$this->ordering = $ordering['ordering'] +1;

		debug($this->ordering);

		parent::AbstractTable($version, $db, $course_id, $import_dir, $old_id_to_new_id);
	}

	function getOldID($row) {
		return $row[0];
	}

	// private
	function convert($row) {
		return $row;
	}

	// private
	function generateSQL($row) {
		$sql = 'INSERT INTO '.TABLE_PREFIX.'content VALUES ';
		$sql .= '('.$row['new_id'].','; // content_id
		$sql .= $this->course_id .',';  // course_id
		if ($row[1] == 0) { // content_parent_id
			$sql .= 0;
		} else {
			$sql .= $this->getNewID($row[1]);
		}
		$sql .= ',';

		if ($row[1] == 0) {
			// find the new ordering:
			$sql .= $this->ordering . ',';
			$this->ordering ++;
		} else {
			$sql .= $row[2].',';
		}

		$sql .= "'".$row[3]."',"; // last_modified
		$sql .= $row[4] . ','; // revision
		$sql .= $row[5] . ','; // formatting
		$sql .= "'".$row[6]."',"; // release_date
		$sql .= "'".$row[7]."',"; // keywords
		$sql .= "'".$row[8]."',"; // content_path
		$sql .= "'".$row[9]."',"; // title
		$sql .= "'".$row[10]."',0)"; // text

		return $sql;
	}
}

//---------------------------------------------------------------------
class RelatedContentTable extends AbstractTable {
	var $tableName = 'related_content';

	var $primaryIDField = 'content_id';

	function getOldID($row) {
		return $row[0];
	}

	// private
	function convert($row) {
		return $row;
	}

	// private
	function generateSQL($row) {
		$sql = 'INSERT INTO '.TABLE_PREFIX.'related_content VALUES ';
		$sql .= '('.$this->new_parent_ids[$row['0']].','. $this->new_parent_ids[$row[1]].')';

		return $sql;
	}
}

//---------------------------------------------------------------------
class CourseStatsTable extends AbstractTable {
	var $tableName = 'course_stats';
	var $primaryIDField = 'login_date'; // never actually used

	function getOldID($row) {
		return FALSE;
	}

	function getParentID($row) {
		return FALSE;
	}

	// private
	function convert($row) {
		return $row;
	}

	// private
	function generateSQL($row) {
		// insert row
		$sql = 'INSERT INTO '.TABLE_PREFIX.'course_stats VALUES ';
		$sql .= '('.$this->course_id.",";
		$sql .= "'".$row[0]."',"; //login_date
		$sql .= "'".$row[1]."',"; //guests
		$sql .= "'".$row[2]."'"; //members
		$sql .= ')';

		return $sql;
	}
}

?>