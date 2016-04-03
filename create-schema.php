<?php

if( PHP_SAPI != 'cli' ) exit;

require_once __DIR__ . '/vendor/autoload.php';

# Clone MediaWiki core
$IP = '/srv/www/mediawiki-farm/mediawiki/master';

# Read each DefaultSettings’s version
//$config = readConfigParameters( file_get_contents( $IP.'/includes/DefaultSettings.php' ) );

//var_dump( $parameters );

//var_dump( getCompleteGitHistory( $IP ) );

//var_dump( analyseGitCommit( $IP, 'd82c14fb4fbac288b42ca5918b0a72f33ecb1e69' ) );

$schema = initSchema( $IP );

//var_dump( $schema );

echo outputSchema( $schema )."\n";


/*
 * Git management
 * ==============
 */

/**
 * Get complete history.
 * 
 * @param string $directory MediaWiki code directory with Git history.
 * @return array List of Git commit SHA1s, from the oldest to the newest.
 */
function getCompleteGitHistory( $codeDir ) {
	
	static $git = null;
	if( is_null( $git ) ) $git = new PHPGit\Git();
	$git->setRepository( $codeDir );
	
	$logs = $git->log( 'includes/DefaultSettings.php', array( 'limit' => -1 ) );
	
	# Read the history and extract only hashes
	$commits = array();
	for( $i=count($logs)-1; $i>=0; $i-- )
		$commits[] = $logs[$i]['hash'];
	
	return $commits;
}

/**
 * Analyse a Git commit.
 * 
 * If the file includes/DefaultSettings.php is not modified, do nothing.
 * Else return its content as of committed in the commit.
 * 
 * @param string $codeDir MediaWiki code directory with Git history.
 * @param string $commit Git commit SHA1.
 * @return string|null Content of the DefaultSettings.php file or null if the file is not modified in the commit.
 */
function analyseGitCommit( $codeDir, $commit ) {
	
	static $git = null;
	if( is_null( $git ) ) $git = new PHPGit\Git();
	$git->setRepository( $codeDir );
	
	# Check the commit is a commit
	if( !$git->cat->type( $commit ) == 'commit' )
		return null;
	
	# Search the DefaultSettings.php file
	$tree = $git->tree( $commit, 'includes' );
	$object = '';
	foreach( $tree as $file ) {
		if( $file['file'] == 'DefaultSettings.php' && $file['type'] == 'blob' ) {
			$object = $file['hash'];
			break;
		}
	}
	if( !$object )
		return null;
	
	# Read the content of the file
	return $git->cat->blob( $object );
}

/**
 * Get date of a Git commit.
 * 
 * @param string $codeDir MediaWiki code directory with Git history.
 * @param string $commit Git commit SHA1.
 * @return string|null Content of the DefaultSettings.php file or null if the file is not modified in the commit.
 */
function getGitDate( $codeDir, $commit ) {
	
	static $git = null;
	if( is_null( $git ) ) $git = new PHPGit\Git();
	$git->setRepository( $codeDir );
	
	$show = $git->show( $commit );
	
	
	
	
}


/*
 * Extraction of configuration parameters specifications
 * =====================================================
 */

/**
 * Read a configuration file and extract configuration parameters.
 * 
 * @param string $code PHP code of the file includes/DefaultSettings.php.
 * @return array
 */
function readConfigParameters( $code ) {
	
	# Configuration parameters
	$config = array();
	
	# Get tokens
	$tokens = token_get_all( $code );
	
	//var_dump( $tokens );
	
	# Read each configuration parameter
	for( $i=0; $i<count($tokens); $i++ ) {
		
		if( !is_array( $tokens[$i] ) )
			continue;
		
		if( token_name( $tokens[$i][0] ) == 'T_VARIABLE' ) {
			
			$varname = $tokens[$i][1];
			
			if( substr( $varname, 0, 3 ) != '$wg' )
				continue;
			$i++;
			
			if( is_array( $tokens[$i] ) && token_name( $tokens[$i][0] ) == 'T_WHITESPACE' )
				$i++;
			
			if( $tokens[$i] == '=' ) {
				$i++;
				
				if( is_array( $tokens[$i] ) && token_name( $tokens[$i][0] ) == 'T_WHITESPACE' )
					$i++;
				
				$value = '';
				$type = 0;
				for( ; $i<count($tokens); $i++ ) {
					
					if( $tokens[$i] == ';' ) break;
					elseif( is_string( $tokens[$i] ) ) {
						$value .= $tokens[$i];
						if( ($type === 0 && preg_match( '/^(?:"|\')/', $tokens[$i] )) || $tokens[$i] == '.' ) $type = T_CONSTANT_ENCAPSED_STRING;
					}
					elseif( is_array( $tokens[$i] ) ) {
						$value .= $tokens[$i][1];
						if( $type === 0 ) $type = $tokens[$i][0];
					}
				}
				
				# When a variable is the result from another variable, get the previous result
				if( preg_match( '/^\&?(\$wg[a-zA-Z0-9]+)$/', $value, $matches ) && array_key_exists( $matches[1], $config ) )
					$type = $config[$matches[1]][count($config[$matches[1]])-1][1];
				
				if( !array_key_exists( $varname, $config ) )
					$config[$varname] = array();
				
				$config[$varname][] = array( $value, $type );
			}
		}
	}
	
	/*# Another pass to find indirect types
	foreach( $config as $param => &$value ) {
		
		# When a variable is the result from another variable, get the previous result
		if( preg_match( '/^\&?(\$wg[a-zA-Z0-9]+)$/', $value[count($value)-1][0], $matches ) && array_key_exists( $matches[1], $config ) )
			$value[count($value)-1][1] = $config[$matches[1]][count($config[$matches[1]])-1][1];
	}*/
	
	return $config;
}

/**
 * Determination of the type of a configuration parameters.
 * 
 * @param string $value Current value of the parameter.
 * @return string|null
 */
function getParamType( $value ) {
	
	if( $value[0] == 'false' || $value[0] == 'true' || $value[0] == 'FALSE' || $value[0] == 'TRUE' || token_name( $value[1] ) == 'T_STRING' )
		return 'boolean';
	elseif( $value[0] == 'null' || $value[0] == 'NULL' || token_name( $value[1] ) == 'T_STRING' )
		return 'null';
	elseif( $value[0][0] == '"' || $value[0][0] == '\'' || $value[0][strlen($value[0])-1] == '"' || $value[0][strlen($value[0])-1] == '\'' || token_name( $value[1] ) == 'T_CONSTANT_ENCAPSED_STRING' )
		return 'string';
	elseif( preg_match( '/^[0-9]+$/', $value[0] ) )
		return 'integer';
	elseif( preg_match( '/^[0-9.]+$/', $value[0] ) )
		return 'number';
	elseif( token_name( $value[1] ) == 'T_ARRAY' )
		return 'object';
	
	return null;
}


/*
 * Schema management
 * =================
 */

/**
 * Initialise a schema from Git history.
 * 
 * @param string $codeDir MediaWiki code directory with Git history.
 * @return array Internal representation of the schema.
 */
function initSchema( $codeDir ) {
	
	$schema = array();
	
	$commits = getCompleteGitHistory( $codeDir );
	
	$i = 0;
	foreach( $commits as $commit ) {
		
		$content = analyseGitCommit( $codeDir, $commit );
		$config = readConfigParameters( $content );
		$schema = addSchema( $schema, $config, $commit );
		//if( $i++ == 100 ) break;
	}
	
	//var_dump($schema);
	return $schema;
}

/**
 * Add a Git commit to an existing schema.
 * 
 * @param array $schema Internal representation of the schema.
 * @param array $config Internal representation of the configuration file.
 * @param string $commit Git commit SHA1.
 * @return array Internal representation of the schema with the new commit added.
 */
function addSchema( $schema, $config, $commit ) {
	
	$version = '';
	if( array_key_exists( '$wgVersion', $config ) )
		$version = preg_replace( '/^(?:"|\')(.*)(?:"|\')$/', '$1', $config['$wgVersion'][count($config['$wgVersion'])-1][0] );
	
	foreach( $config as $param => $values ) {
		
		if( !array_key_exists( $param, $schema ) )
			$schema[$param] = addParameterSchema( $param, $values, $version, $commit );
		
		else
			$schema[$param] = updateParameterSchema( $param, $schema[$param], $values, $version, $commit );
	}
	
	return $schema;
}

/**
 * Add a new parameter to a schema.
 * 
 * @param array $parameter Name of the parameter.
 * @param array $values Internal representation of the new parameter.
 * @param string $version MediaWiki version.
 * @param string $commit Git commit SHA1.
 * @return array Internal representation of the parameter.
 */
function addParameterSchema( $parameter, $values, $version, $commit ) {
	
	if( !$version ) $version = '0.0.0';
	
	$config = array(
		'type' => array(),
		'description' => '',
		'version' => '>='.$version,
		'source' => array( 'git#'.substr($commit,0,8).' added' ),
	);
	
	$type = getParamType( $values[count($values)-1] );
	
	if( !is_null( $type ) ) {
		$config['type'][] = $type;
		if( $type == 'string' ) $config['default'] = typeString( $values[count($values)-1][0] );
		elseif( $type == 'boolean' ) $config['default'] = typeBoolean( $values[count($values)-1][0] );
		elseif( $type == 'null' ) $config['default'] = typeNull( $values[count($values)-1][0] );
		elseif( $type == 'integer' || $type == 'number' || $type == 'object' ) $config['default'] = $values[count($values)-1][0];
	}
	else {
		$config['code'] = $values[count($values)-1][0];
		var_dump( $parameter );
		var_dump($values);
	}
	return $config;
}

/**
 * Update a parameter in a schema.
 * 
 * @param array $parameter Name of the parameter.
 * @param array $config Current internal representation of the parameter.
 * @param array $values Internal representation of the parameter.
 * @param string $version MediaWiki version.
 * @param string $commit Git commit SHA1.
 * @return array Internal representation of the parameter.
 */
function updateParameterSchema( $parameter, $config, $values, $version, $commit ) {
	
	if( !$version ) $version = '0.0.0';
	
	# Check if the default value has been modified
	$modified = false;
	$type = getParamType( $values[count($values)-1] );
	$default = null;
	$code = null;
	if( !in_array( $type, $config['type'] ) && !(is_null( $type ) && count( $config['type'] ) == 0) ) $modified = true;
	if( !is_null( $type ) ) {
		if( $type == 'string' ) $default = typeString( $values[count($values)-1][0] );
		elseif( $type == 'boolean' ) $default = typeBoolean( $values[count($values)-1][0] );
		elseif( $type == 'null' ) $default = typeNull( $values[count($values)-1][0] );
		elseif( $type == 'integer' || $type == 'number' || $type == 'object' ) $default = $values[count($values)-1][0];
		if( in_array( $type, array( 'string', 'integer', 'number', 'boolean', 'object' ) ) && $default !== $config['default'] ) $modified = true;
	}
	else {
		$code = $values[count($values)-1][0];
		if( $code !== $config['code'] ) $modified = true;
	}
	
	# If nothing is modified, that’s fine and just return
	if( !$modified )
		return $config;
	
	# Parameter was removed and now reinstalled
	$oldVersionConstraint = $config['version'];
	if( !preg_match( '/^.*>=(?:0\\.0\\.0|1\\.[1-9][0-9]*\\.[0-9]+)[a-zA-Z0-9-]*$/', $config['version'] ) ) {
		$config['version'] .= ' || >=' . $version;
	}
	
	# When the type changes
	if( !in_array( $type, $config['type'] ) && !(is_null( $type ) && count( $config['type'] ) == 0) ) {
		
		if( !array_key_exists( 'types', $config ) ) $config['types'] = array();
		if( count( $config['types'] ) == 0 ) $config['types'][] = array( $oldVersionConstraint, $config['type'] );
		
		$thisOldVersionConstraint = $config['types'][count($config['types'])-1][0];
		$thisNewVersionConstraint = '>='.$version;
		if( preg_match( '/^(.*)>=(0\\.0\\.0|1\\.[1-9][0-9]*\\.[0-9]+)(?:\\.([0-9]+))?([a-zA-Z0-9-]*)$/', $thisOldVersionConstraint, $matches ) ) {
			
			$subversion = (int) $matches[3];
			if( $matches[2].$matches[4] == $version ) {
				$thisOldVersionConstraint = $matches[1].'>='.$matches[2].'.'.$subversion.$matches[4] . ' <'.$matches[1].$matches[2].'.'.($subversion+1).$matches[4];
				$thisNewVersionConstraint = '>='.$matches[2].'.'.($subversion+1).$matches[4];
			}
			else $thisOldVersionConstraint .= ' <'.$version;
		}
		
		$config['types'][count($config['types'])-1][0] = $thisOldVersionConstraint;
		$config['types'][] = array( $thisNewVersionConstraint, array( $type ) );
		$config['type'] = array( $type );
	}
	
	# When the default changes
	$archivePattern = false;
	if( !is_null( $type ) ) {
		
		if( !array_key_exists( 'defaults', $config ) ) $config['defaults'] = array();
		if( count( $config['defaults'] ) == 0 ) $config['defaults'][] = array( $oldVersionConstraint, $config['default'] );
		
		$thisOldVersionConstraint = $config['defaults'][count($config['defaults'])-1][0];
		$thisNewVersionConstraint = '>='.$version;
		if( preg_match( '/^(.*)>=(0\\.0\\.0|1\\.[1-9][0-9]*\\.[0-9]+)(?:\\.([0-9]+))?([a-zA-Z0-9-]*)$/', $thisOldVersionConstraint, $matches ) ) {
			
			$subversion = (int) $matches[3];
			if( $matches[2].$matches[4] == $version ) {
				$thisOldVersionConstraint = $matches[1].'>='.$matches[2].'.'.$subversion.$matches[4] . ' <'.$matches[1].$matches[2].'.'.($subversion+1).$matches[4];
				$thisNewVersionConstraint = '>='.$matches[2].'.'.($subversion+1).$matches[4];
			}
			else $thisOldVersionConstraint .= ' <'.$version;
		}
		
		$config['defaults'][count($config['defaults'])-1][0] = $thisOldVersionConstraint;
		$config['defaults'][] = array( $thisNewVersionConstraint, $default );
		$config['default'] = $default;
		$archivePattern = true;
	}
	else {
		
		if( !array_key_exists( 'codes', $config ) ) $config['codes'] = array();
		if( count( $config['codes'] ) == 0 ) $config['codes'][] = array( $oldVersionConstraint, $config['code'] );
		
		$thisOldVersionConstraint = $config['codes'][count($config['codes'])-1][0];
		$thisNewVersionConstraint = '>='.$version;
		if( preg_match( '/^(.*)>=(0\\.0\\.0|1\\.[1-9][0-9]*\\.[0-9]+)(?:\\.([0-9]+))?([a-zA-Z0-9-]*)$/', $thisOldVersionConstraint, $matches ) ) {
			
			$subversion = (int) $matches[3];
			if( $matches[2].$matches[4] == $version ) {
				$thisOldVersionConstraint = $matches[1].'>='.$matches[2].'.'.$subversion.$matches[4] . ' <'.$matches[1].$matches[2].'.'.($subversion+1).$matches[4];
				$thisNewVersionConstraint = '>='.$matches[2].'.'.($subversion+1).$matches[4];
			}
			else $thisOldVersionConstraint .= ' <'.$version;
		}
		
		$config['codes'][count($config['codes'])-1][0] = $thisOldVersionConstraint;
		$config['codes'][] = array( $thisNewVersionConstraint, $code );
		$config['code'] = $code;
		$archivePattern = true;
	}
	
	# Pattern
	if( $archivePattern && $config['pattern'] ) {
		
		if( !array_key_exists( 'patterns', $config ) ) $config['patterns'] = array();
		if( count( $config['patterns'] ) == 0 ) $config['patterns'][] = array( $oldVersionConstraint, $config['pattern'] );
		
		$thisOldVersionConstraint = $config['patterns'][count($config['patterns'])-1][0];
		$thisNewVersionConstraint = '>='.$version;
		if( preg_match( '/^(.*)>=(0\\.0\\.0|1\\.[1-9][0-9]*\\.[0-9]+)(?:\\.([0-9]+))?([a-zA-Z0-9-]*)$/', $thisOldVersionConstraint, $matches ) ) {
			
			$subversion = (int) $matches[3];
			if( $matches[2].$matches[4] == $version ) {
				$thisOldVersionConstraint = $matches[1].'>='.$matches[2].'.'.$subversion.$matches[4] . ' <'.$matches[1].$matches[2].'.'.($subversion+1).$matches[4];
				$thisNewVersionConstraint = '>='.$matches[2].'.'.($subversion+1).$matches[4];
			}
			else $thisOldVersionConstraint .= ' <'.$version;
		}
		
		$config['patterns'][count($config['patterns'])-1][0] = $thisOldVersionConstraint;
	}
	
	# Source
	$config['source'][] = 'git#'.substr($commit,0,8).' modified';
	
	return $config;
}

/**
 * Interpretation of a string of type string.
 * 
 * @param string $value Value assumed to be a string.
 * @return string Value with correct type or input value if error.
 */
function typeString( $value ) {
	
	if( preg_match( '/\$/', $value ) ) return $value;
	
	$withoutDoubleQuotes = preg_replace( '/^"(.*)"$/', '$1', $value );
	if( !preg_match( '/"/', $withoutDoubleQuotes ) ) return $withoutDoubleQuotes;
	
	$withoutSingleQuotes = preg_replace( '/^\'(.*)\'$/', '$1', $value );
	if( !preg_match( '/\'/', $withoutSingleQuotes ) ) return $withoutSingleQuotes;
	
	return $value;
}

/**
 * Interpretation of a string of type boolean.
 * 
 * @param string $value Value assumed to be a boolean.
 * @return bool|string Value with correct type or input value if error.
 */
function typeBoolean( $value ) {
	
	if( $value == 'true' || $value == 'TRUE' ) return true;
	elseif( $value == 'false' || $value == 'FALSE' ) return false;
	return $value;
}

/**
 * Interpretation of a string of type null.
 * 
 * @param string $value Value assumed to be null.
 * @return null|string Value with correct type or input value if error.
 */
function typeNull( $value ) {
	
	if( $value == 'null' || $value == 'NULL' ) return null;
	return $value;
}

/**
 * Creation of the JSON Schema.
 * 
 * @param array $schema Internal representation of the schema.
 * @return string JSON Schema.
 */
function outputSchema( $schema ) {
	
	foreach( $schema as $param => &$config ) {
		
		$backup = $config;
		$config = array(
			'type' => count( $backup['type'] ) == 1 ? $backup['type'][0] : $backup['type'],
			'description' => $backup['description'],
		);
		
		if( array_key_exists( 'pattern', $backup ) ) $config['pattern'] = $backup['pattern'];
		if( array_key_exists( 'default', $backup ) ) $config['default'] = $backup['default'];
		if( array_key_exists( 'code', $backup ) ) $config['code'] = $backup['code'];
		
		if( array_key_exists( 'types', $backup ) && count( $backup['types'] ) > 0 ) {
			$config['types'] = array();
			foreach( $backup['types'] as $type )
				$config['types'][$type[0]] = (is_array( $type[1] ) && count( $type[1] ) == 1) ? $type[1][0] : $type[1];
		}
		
		if( array_key_exists( 'patterns', $backup ) && count( $backup['patterns'] ) > 0 ) {
			$config['patterns'] = array();
			foreach( $backup['patterns'] as $pattern )
				$config['patterns'][$pattern[0]] = $pattern[1];
		}
		
		if( array_key_exists( 'defaults', $backup ) && count( $backup['defaults'] ) > 0 ) {
			$config['defaults'] = array();
			foreach( $backup['defaults'] as $default )
				$config['defaults'][$default[0]] = $default[1];
		}
		
		if( array_key_exists( 'codes', $backup ) && count( $backup['codes'] ) > 0 ) {
			$config['codes'] = array();
			foreach( $backup['codes'] as $code )
				$config['codes'][$code[0]] = $code[1];
		}
		
		if( count( $backup['source'] ) > 0 )
			$config['source'] = $backup['source'];
	}
	
	$globalSchema = array(
		'$schema' => 'https://localhost/mediawiki-config-metaschema#',	
		'name' => 'MediaWiki configuration parameters.',
		'version' => '1.27.0-alpha',
		'git' => '0a5b872a69018ec2d74efe4ddc3910d729b1fd18',
		'type' => 'object',
		'additionalProperties' => true,
		'properties' => $schema,
	);
	
	return json_encode( $globalSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

