<?php

/**
 * A decorator, which needs to be attached to every DataObject that you wish to search with Sphinx.
 * 
 * Provides a few end developer useful methods, like search, but mostly is here to provide two internal facilities
 *  - Provides introspection methods, for getting the fields and relationships of an object in Sphinx-compatible form
 *  - Provides hooks into writing, deleting and schema rebuilds, so we can reindex & rebuild the Sphinx configuration automatically 
 * 
 * Note: Children DataObject's inherit their Parent's Decorators - there is no need to at this extension to BlogEntry if SiteTree already has it, and doing so will probably cause issues.
 * 
 * @author Hamish Friedlander <hamish@silverstripe.com>
 */
class SphinxSearchable extends DataObjectDecorator {
	
	/**
	 * When writing many SphinxSearchable items (such as during a migration import) the burden of keeping the Sphinx index updated in realtime is
	 * both unneccesary and prohibitive. You can temporarily disable indexed, and enable it again after the bulk write, using these two functions
	 */
	
	static $reindex_on_write = true;

	static function disable_indexing() {
		self::$reindex_on_write = false;
	}
	
	static function reenable_indexing() {
		self::$reindex_on_write = true;
		// We haven't been tracking dirty writes, so the only way to ensure the results are up to date is a full reindex
		singleton('Sphinx')->reindex();
	}

	/**
	 * Returns a list of all classes that are SphinxSearchable
	 * @return array[string]
	 */
	static function decorated_classes() {
		return array_filter(ClassInfo::subclassesFor('DataObject'), create_function('$class', 'return Object::has_extension($class, "SphinxSearchable");'));
	}
	
	/**
	 * Add field to record whether this row is in primary index or delta index
	 */
	function extraStatics() {
		return array(
			'db' => array(
				'SphinxPrimaryIndexed' => 'Boolean'
			),
			'indexes' => array(
				'SphinxPrimaryIndexed' => true
			)
		);
	}
	
	/**
	 * Find the 'Base ID' for this DataObject. The base id is a numeric ID that is unique to the group of DataObject classes that share a common base class (the
	 * class that immediately inherits from DataObject). This is used often in SphinxSearch as part of the globally unique document ID
	 */
	function sphinxBaseID() {
		return SphinxSearch::unsignedcrc(ClassInfo::baseDataClass($this->owner->class));
	}
	
	/**
	 * Find the 'Document ID' for this DataObject. The base id is a 64 bit numeric ID (represented by a BCD string in PHP) that is globally unique to this document
	 * across the entire database. It is formed from BaseID << 32 + DataObjectID, since DataObject IDs are unique within all subclasses of a common base class
	 */
	function sphinxDocumentID() {
		return SphinxSearch::combinedwords($this->sphinxBaseID(), $this->owner->ID);
	}
	
	/**
	 * Passes search through to SphinxSearch.
	 */
	function search() {
		$args = func_get_args();
		array_unshift($args, $this->owner->class);
		return call_user_func_array(array('SphinxSearch','search'), $args);
	}
	
	/**
	 * Mark this document as dirty in the main indexes by setting (overloaded) SphinxPrimaryIndexed to false
	 */
	function sphinxDirty() {
		$sing = singleton('Sphinx');
		$mains = array_filter($sing->indexes($this->owner->class), create_function('$i', 'return !$i->isDelta;'));
		$names = array_map(create_function('$idx', 'return $idx->Name;'), $mains);
		
		$sing->connection()->UpdateAttributes(implode(';', $names), array("_dirty"), array($this->sphinxDocumentID() => array(1)));
	}
	
	/**
	 * Rebuild the sphinx indexes for all indexes that apply to this class (usually the ClassName + and variants)
	 */
	function reindex() {
		$sing = singleton('Sphinx');
		$deltas = array_filter($sing->indexes($this->owner->class), create_function('$i', 'return $i->isDelta;'));
		$sing->reindex($deltas);
	}
	
	/**
	 * Get a snippet highlighting the search terms
	 * 
	 * @todo This is not super fast because of round trip latency. Sphinx supports passing more than one document at a time, but because we use heaps of indexes we can't really take
	 * advantage of that. Maybe we can fix that somehow?
	 */
	function buildExcerpt($terms, $field = 'Content', $opts = array()) {
		$con = singleton('Sphinx')->connection();
		$res = $con->BuildExcerpts(array($this->owner->$field), $this->owner->class, $terms, $opts);
		return array_pop($res);
	}
	
	/*
	 * INTROSPECTION FUNCTIONS
	 * 
	 * Helper functions to allow SphinxSearch to introspect a DataObject to get the fields it should inject into sphinx.conf
	 */
	
	function sphinxFields() {
		$ret = array();
		
		foreach (ClassInfo::ancestry($this->owner->class, true) as $class) {
			$fields = DataObject::database_fields($class);
			$conf = $this->owner->stat('sphinx');
			
			$fieldOverrides = ($conf && isset($conf['fields'])) ? $conf['fields'] : array();
			
			if ($fields) foreach($fields as $name => $type) {
				if     (isset($fieldOverrides[$name]))           $type = $fieldOverrides[$name];
				elseif (preg_match('/^(\w+)\(/', $type, $match)) $type = $match[1];
				
				$ret[$name] = array($class, $type);
			}
		}
		
		return $ret;
	}
	
	function sphinxFieldConfig() {
		$base = ClassInfo::baseDataClass($this->owner->class);
		$baseid = SphinxSearch::unsignedcrc($base);
		$classid = SphinxSearch::unsignedcrc($this->owner->class);
		
		$bt = defined('Database::USE_ANSI_SQL') ? "\"" : "`";
		
		$select = array(
			// Select the 64 bit combination baseid << 32 | itemid as the document ID
			"($baseid<<32)|{$bt}$base{$bt}.{$bt}ID{$bt} AS id", 
			// And select each value individually for filtering and easy access 
			"{$bt}$base{$bt}.ID AS _id",
			"$baseid AS _baseid",
			"$classid AS _classid",
			"0 as _dirty"
		); 
		$attributes = array('sql_attr_uint = _id', 'sql_attr_uint = _baseid', 'sql_attr_uint = _classid', 'sql_attr_bool = _dirty');
				
		foreach($this->sphinxFields() as $name => $info) {
			list($class, $type) = $info;
			
			switch ($type) {
				case 'Varchar':
				case 'Text':
				case 'HTMLVarchar':
				case 'HTMLText':
					$select[] = "{$bt}$class{$bt}.{$bt}$name{$bt} AS {$bt}$name{$bt}";
					break;
					
				case 'Boolean':
					$select[] = "{$bt}$class{$bt}.{$bt}$name{$bt} AS {$bt}$name{$bt}";
					$attributes[] = "sql_attr_bool = $name";
					break;

				case 'Date':
				case 'SSDatetime':
					$select[] = "UNIX_TIMESTAMP({$bt}$class{$bt}.{$bt}$name{$bt}) AS {$bt}$name{$bt}";
					$attributes[] = "sql_attr_timestamp = $name";
					break;

				case 'ForeignKey':
					$select[] = "{$bt}$class{$bt}.{$bt}$name{$bt} AS {$bt}$name{$bt}";
					$attributes[] = "sql_attr_uint = $name";
					break;
					
				case 'CRCOrdinal':
					$select[] = "CRC32({$bt}$class{$bt}.{$bt}$name{$bt}) AS {$bt}$name{$bt}";
					$attributes[] = "sql_attr_uint = $name";
					break;						
			}
		}
		
		return array('select' => $select, 'attributes' => $attributes);
	}
	
	function sphinxHasManyAttributes() {
		$attributes = array();
		$bt = defined('Database::USE_ANSI_SQL') ? "\"" : "`";
		
		foreach (ClassInfo::ancestry($this->owner->class) as $class) {
			$has_many = Object::uninherited_static($class, 'has_many');
			if ($has_many) foreach($has_many as $name => $refclass) {
				
				$qry = $this->owner->getComponentsQuery($name);
				$cid = $this->owner->getComponentJoinField($name);
				
				$reftables = ClassInfo::ancestry($refclass,true); $reftable = array_pop($reftables);

				$qry->select(array("{$bt}$reftable{$bt}.{$bt}$cid{$bt} AS id", "{$bt}$reftable{$bt}.{$bt}ID{$bt} AS $name"));
				$qry->where = array();
				singleton($refclass)->extend('augmentSQL', $qry);
				
				$attributes[] = "sql_attr_multi = uint $name from query; " . $qry;
			}
		}
		
		return $attributes;
	}
	
	function sphinxManyManyAttributes() {
		$attributes = array();
		$bt = defined('Database::USE_ANSI_SQL') ? "\"" : "`";
		
		$base = ClassInfo::baseDataClass($this->owner->class);
		$baseid = SphinxSearch::unsignedcrc($base);
		
		$conf = $this->owner->stat('sphinx');
		if (!isset($conf['filterable_many_many'])) return $attributes;

		// Build an array with the keys being the many_manys to include as attributes
		$many_manys = $conf['filterable_many_many'];
		if     (is_string($many_manys) && $many_manys != '*') $many_manys = array($many_manys => $many_manys);
		elseif (is_array($many_manys))                        $many_manys = array_combine($many_manys, $many_manys);
		
		foreach (ClassInfo::ancestry($this->owner->class) as $class) {
			$many_many = (array) Object::uninherited_static($class, 'many_many');
			if ($many_manys != '*') $many_many = array_intersect_key($many_many, $many_manys); // Filter to only include specified many_manys
			
			if ($many_many) foreach ($many_many as $name => $refclass) {
				list($parentClass, $componentClass, $parentField, $componentField, $table) = $this->owner->many_many($name);
				$componentBaseClass = ClassInfo::baseDataClass($componentClass);
		
				$qry = singleton($componentClass)->extendedSQL(array('true'), null, null, "INNER JOIN {$bt}$table{$bt} ON {$bt}$table{$bt}.$componentField = {$bt}$componentBaseClass{$bt}.ID" );
				$qry->select(array("($baseid<<32)|{$bt}$table{$bt}.{$bt}$parentField{$bt} AS id", "{$bt}$table{$bt}.{$bt}$componentField{$bt} AS $name"));
				$qry->groupby = array();
				
				$attributes[] = "sql_attr_multi = uint $name from query; " . $qry;
			}
		}
		
		return $attributes;
	}
	
	/*
	 * HOOK FUNCTIONS
	 * 
	 * Functions to connect regular silverstripe operations with sphinx operations, to maintain syncronisation
	 */

	// Make sure that SphinxPrimaryIndexed gets set to false, so this record is picked up on delta reindex
	public function augmentWrite(&$manipulation) {
		foreach (ClassInfo::ancestry($this->owner->class, true) as $class) {
			$fields = DataObject::database_fields($class);
			if (isset($fields['SphinxPrimaryIndexed'])) break;
		}
		
		$manipulation[$class]['fields']['SphinxPrimaryIndexed'] = 0;
	}
	
	// After delete, mark as dirty in main index (so only results from delta index will count), then update the delta index  
	function onAfterWrite() {
		if (!self::$reindex_on_write) return;
		$this->sphinxDirty();
		$this->reindex();
	}
	
	// After delete, mark as dirty in main index (so only results from delta index will count), then update the delta index
	function onAfterDelete() {
		if (!self::$reindex_on_write) return;
		$this->sphinxDirty();
		$this->reindex();
	}
	
	/*
	 * This uses a function called only on dev/build construction to patch in also calling Sphinx::configure when dev/build is called
	 */
	static $sphinx_configure_called = false;
	function requireDefaultRecords() {
		if (self::$sphinx_configure_called) return;
		
		singleton('Sphinx')->check();
		singleton('Sphinx')->configure();
		singleton('Sphinx')->reindex();
		self::$sphinx_configure_called = true;
	}
}
