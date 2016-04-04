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
			
			if( !preg_match( '/^\\$(wg[a-zA-Z0-9]+)$/', $varname, $matches ) )
				continue;
			$varname = $matches[1];
			$i++;
			
			if( is_array( $tokens[$i] ) && token_name( $tokens[$i][0] ) == 'T_WHITESPACE' )
				$i++;
			
			if( $tokens[$i] == '=' ) {
				$i++;
				
				if( is_array( $tokens[$i] ) && token_name( $tokens[$i][0] ) == 'T_WHITESPACE' )
					$i++;
				
				$value = '';
				$token = 0;
				$type = '';
				$result = null;
				for( ; $i<count($tokens); $i++ ) {
					
					if( $tokens[$i] == ';' ) break;
					elseif( is_string( $tokens[$i] ) ) {
						$value .= $tokens[$i];
						if( $token == 0 && $tokens[$i] == '[' ) {
							$token = T_ARRAY;
							$type = 'object';
						}
						elseif( ($token == 0 && preg_match( '/^(?:"|\')/', $tokens[$i] )) || ($tokens[$i] == '.' && token_name( $token ) != 'T_ARRAY') )
							$token = T_CONSTANT_ENCAPSED_STRING;
						elseif( ($type == 'integer' || $type == 'number') && !is_null( $result ) ) {
							if( $tokens[$i] == '*' ) $result[1] = '*';
							else $result = null;
						}
					}
					elseif( is_array( $tokens[$i] ) ) {
						if( token_name( $tokens[$i][0] ) == 'T_CLOSE_TAG' ) break;
						$value .= $tokens[$i][1];
						if( $token == 0 && token_name( $tokens[$i][0] ) == 'T_LNUMBER' ) {
							$type = 'integer';
							if( is_null( $result ) ) $result = array( intval( $tokens[$i][1], 0 ), '' );
							elseif( $result[1] == '*' ) $result = array( $result[0] * intval( $tokens[$i][1], 0 ), '' );
						}
						elseif( $token == 0 && token_name( $tokens[$i][0] ) == 'T_DNUMBER' ) {
							$type = 'number';
							if( is_null( $result ) ) $result = array( floatval( $tokens[$i][1] ), '' );
							elseif( $result[1] == '*' ) $result = array( $result[0] * floatval( $tokens[$i][1] ), '' );
						}
						elseif( $token == 0 && token_name( $tokens[$i][0] ) == 'T_STRING' && typeBoolean( $tokens[$i][1] ) !== $tokens[$i][1] ) {
							$type = 'boolean';
							$result = typeBoolean( $tokens[$i][1] );
						}
						if( $token === 0 ) $token = $tokens[$i][0];
						if( token_name( $token ) == 'T_ARRAY' ) {
							$type = 'object';
						}
					}
				}
				
				# When a variable is the result from another variable, get the previous result
				if( preg_match( '/^\\&?\\$(wg[a-zA-Z0-9]+)$/', $value, $matches ) && array_key_exists( $matches[1], $config ) ) {
					
					$token = $config[$matches[1]][count($config[$matches[1]])-1][1];
					$type = $config[$matches[1]][count($config[$matches[1]])-1][2];
					$result = $config[$matches[1]][count($config[$matches[1]])-1][3];
				}
				
				if( !array_key_exists( $varname, $config ) )
					$config[$varname] = array();
				
				$config[$varname][] = array( trim( $value ), $token, $type, $result );
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
	
	if( $value[2] == 'boolean' || (($value[0] == 'false' || $value[0] == 'true' || $value[0] == 'FALSE' || $value[0] == 'TRUE') && token_name( $value[1] ) == 'T_STRING') )
		return 'boolean';
	elseif( ($value[0] == 'null' || $value[0] == 'NULL') && token_name( $value[1] ) == 'T_STRING' )
		return 'null';
	elseif( $value[0][0] == '"' || $value[0][0] == '\'' || $value[0][strlen($value[0])-1] == '"' || $value[0][strlen($value[0])-1] == '\'' || token_name( $value[1] ) == 'T_CONSTANT_ENCAPSED_STRING' )
		return 'string';
	elseif( $value[2] == 'integer' || preg_match( '/^[0-9-]+$/', $value[0] ) || token_name( $value[1] ) == 'T_LNUMBER' )
		return 'integer';
	elseif( $value[2] == 'number' || preg_match( '/^[0-9.eE-]+$/', $value[0] ) || token_name( $value[1] ) == 'T_DNUMBER' )
		return 'number';
	elseif( $value[2] == 'object' || token_name( $value[1] ) == 'T_ARRAY' )
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
		#if( $commit == 'fd34d0354b6842acb0bb0cd990e508375f390f37' ) break; # introduced MW_VERSION
		#if( substr( $commit, 0, 8 ) == '6e9b4f0e' ) break;
		#if( $i++ == 1000 ) break;
	}
	
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
	
	# Get version from $wgVersion; it exists since 1.2.0alpha
	$version = '0.0.0';
	if( array_key_exists( 'wgVersion', $config ) )
		$version = preg_replace( '/^(?:"|\')(.*)(?:"|\')$/', '$1', $config['wgVersion'][count($config['wgVersion'])-1][0] );
	
	# The constant MW_VERSION was introduced and quickly reverted around 1.19alpha
	if( $version == 'MW_VERSION' ) $version = '1.19alpha';
	
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
	
	$php = $values[count($values)-1][0];
	
	$config = array(
		'type' => array(),
		'description' => '',
		'version' => '>='.$version,
		'php' => $php,
		'source' => array( 'git#'.substr($commit,0,8).' added' ),
	);
	
	$type = getParamType( $values[count($values)-1] );
	
	if( !is_null( $type ) ) {
		$config['type'][] = $type;
		if( $type == 'string' ) $config['default'] = typeString( $php );
		elseif( $type == 'boolean' ) $config['default'] = typeBoolean( $php, $values[count($values)-1] );
		elseif( $type == 'null' ) $config['default'] = typeNull( $php );
		elseif( $type == 'integer' ) $config['default'] = typeInteger( $php, $values[count($values)-1] );
		elseif( $type == 'number' ) $config['default'] = typeNumber( $php, $values[count($values)-1] );
		elseif( $type == 'object' ) $config['default'] = $php;
	}
	else {
		$config['code'] = $values[count($values)-1][0];
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
	
	# Get PHP code
	$php = $values[count($values)-1][0];
	
	# Some workaround for UTF-8 invalid characters - json_encode doesn’t like (at all) these faulty characters
	if( $parameter == 'wgBrowserBlackList' ) {
		if( $commit == 'd4db1caa6ae3098b34a15fbc5808b52ae8ab8895' ) $php = str_replace( "\xC3\x3F", '<c3><3f>', $php );
		if( $commit == '876a60ad07239aec3392903c5fec95d1b8edaa82' ) $php = str_replace( array( "\xFE\x20", "\xF0\x20", "\xDE\x20", "\xD0\x20" ), array( '<fe> ', '<f0> ', '<de> ', '<d0> ' ), $php );
	}
	elseif( $parameter == 'wgUrlProtocols' ) {
		if( $commit == '876a60ad07239aec3392903c5fec95d1b8edaa82' ) $php = str_replace( "\xE6\x76", '<e6>v', $php );
	}
	
	#$jsontest = json_encode( $php, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	#if( !$jsontest ) {
	#	var_dump(json_last_error_msg());
	#	var_dump( $parameter );
	#	var_dump( $commit );
	#	#$php = 'null';
	#	#$values = array( 'null', T_STRING );
	#}
	
	# Check if the default value has been modified
	$modified = false;
	$type = getParamType( $values[count($values)-1] );
	$default = null;
	if( !in_array( $type, $config['type'] ) && !(is_null( $type ) && count( $config['type'] ) == 0) ) $modified = true;
	if( !is_null( $type ) ) {
		if( $type == 'string' ) $default = typeString( $php );
		elseif( $type == 'boolean' ) $default = typeBoolean( $php, $values[count($values)-1] );
		elseif( $type == 'null' ) $default = typeNull( $php );
		elseif( $type == 'integer' ) $default = typeInteger( $php, $values[count($values)-1] );
		elseif( $type == 'number' ) $default = typeNumber( $php, $values[count($values)-1] );
		elseif( $type == 'object' ) $default = $php;
		if( in_array( $type, array( 'string', 'integer', 'number', 'boolean', 'object' ) ) && array_key_exists( 'default', $config ) && $default !== $config['default'] ) $modified = true;
	}
	if( array_key_exists( 'php', $config ) && $php !== $config['php'] ) $modified = true;
	
	# Remove default if the value is PHP code
	if( is_null( $type ) || $php === $default )
		unset( $config['default'] );
	
	# PHP code
	$oldphp = $config['php'];
	$config['php'] = $php;
	
	# If nothing is modified, that’s fine and just return
	if( !$modified )
		return $config;
	
	# Parameter was removed and now reinstalled
	$oldVersionConstraint = $config['version'];
	if( !preg_match( '/^.*>=[0-9]+\\.[0-9]+(?:\\.[0-9]+)?[a-zA-Z0-9+-]*$/', $config['version'] ) )
		$config['version'] .= ' || >=' . $version;
	
	# Add default value if type is known
	if( !is_null( $type ) && $php !== $default )
		$config['default'] = $default;
	
	# When the type changes
	if( !in_array( $type, $config['type'] ) && !(is_null( $type ) && count( $config['type'] ) == 0) ) {
		
		if( !array_key_exists( 'types', $config ) ) $config['types'] = array();
		if( count( $config['types'] ) == 0 ) $config['types'][] = array( $oldVersionConstraint, $config['type'] );
		
		$thisOldVersionConstraint = $config['types'][count($config['types'])-1][0];
		$thisNewVersionConstraint = '>='.$version;
		if( preg_match( '/^(.*)>=([0-9]+\\.[0-9]+(?:\\.[0-9]+)?)(?:\\.([0-9]+))?([a-zA-Z0-9+-]*)$/', $thisOldVersionConstraint, $matches ) ) {
			
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
	
	# Add a line in history
	if( !array_key_exists( 'history', $config ) ) $config['history'] = array();
	if( count( $config['history'] ) == 0 ) $config['history'][] = array( $oldVersionConstraint, $oldphp );
	$thisOldVersionConstraint = $config['history'][count($config['history'])-1][0];
	$thisNewVersionConstraint = '>='.$version;
	if( preg_match( '/^(.*)>=([0-9]+\\.[0-9]+(?:\\.[0-9]+)?)(?:\\.([0-9]+))?([a-zA-Z0-9+-]*)$/', $thisOldVersionConstraint, $matches ) ) {
		
		$subversion = (int) $matches[3];
		if( $matches[2].$matches[4] == $version ) {
			$thisOldVersionConstraint = $matches[1].'>='.$matches[2].'.'.$subversion.$matches[4] . ' <'.$matches[1].$matches[2].'.'.($subversion+1).$matches[4];
			$thisNewVersionConstraint = '>='.$matches[2].'.'.($subversion+1).$matches[4];
		}
		else $thisOldVersionConstraint .= ' <'.$version;
	}
	$config['history'][count($config['history'])-1][0] = $thisOldVersionConstraint;
	$config['history'][] = array( $thisNewVersionConstraint, $php );
	
	# Pattern
	if( $config['pattern'] ) {
		
		if( !array_key_exists( 'patterns', $config ) ) $config['patterns'] = array();
		if( count( $config['patterns'] ) == 0 ) $config['patterns'][] = array( $oldVersionConstraint, $config['pattern'] );
		
		$thisOldVersionConstraint = $config['patterns'][count($config['patterns'])-1][0];
		$thisNewVersionConstraint = '>='.$version;
		if( preg_match( '/^(.*)>=([0-9]+\\.[0-9]+(?:\\.[0-9]+)?)(?:\\.([0-9]+))?([a-zA-Z0-9+-]*)$/', $thisOldVersionConstraint, $matches ) ) {
			
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
	$config['source'][] = 'git#'.substr($commit,0,8).' changed';
	
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
	if( $withoutDoubleQuotes != $value && !preg_match( '/"/', $withoutDoubleQuotes ) ) return $withoutDoubleQuotes;
	
	$withoutSingleQuotes = preg_replace( '/^\'(.*)\'$/', '$1', $value );
	if( $withoutSingleQuotes != $value && !preg_match( '/\'/', $withoutSingleQuotes ) ) return $withoutSingleQuotes;
	
	return $value;
}

/**
 * Interpretation of a string of type boolean.
 * 
 * @param string $value Value assumed to be a boolean.
 * @param array $rawtype Raw informations about the type.
 * @return bool|string Value with correct type or input value if error.
 */
function typeBoolean( $value, $rawtype = null ) {
	
	if( is_array( $rawtype ) && $rawtype[2] == 'boolean' ) return $rawtype[3];
	elseif( $value == 'true' || $value == 'TRUE' ) return true;
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
 * Interpretation of a string of type integer.
 * 
 * @param string $value Value assumed to be an integer.
 * @param array $rawtype Raw informations about the type.
 * @return int|string Value with correct type or input value if error.
 */
function typeInteger( $value, $rawtype = null ) {
	
	if( is_array( $rawtype ) && !is_null( $rawtype[3][0] ) ) return $rawtype[3][0];
	elseif( $value == '0' || $value == '0x0' || $value == '00' ) return 0;
	elseif( intval( $value, 0 ) ) return intval( $value, 0 );
	return $value;
}

/**
 * Interpretation of a string of type number.
 * 
 * @param string $value Value assumed to be a number.
 * @param array $rawtype Raw informations about the type.
 * @return float|string Value with correct type or input value if error.
 */
function typeNumber( $value, $rawtype = null ) {
	
	if( is_array( $rawtype ) && $rawtype[2] == 'number' && !is_null( $rawtype[3][0] ) ) return $rawtype[3][0];
	elseif( $value == '0' || $value == '0x0' || $value == '00' ) return 0;
	elseif( floatval( $value ) ) return floatval( $value );
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
			'type' => count( $backup['type'] ) == 1 ? $backup['type'][0] : (count( $backup['type'] ) == 0 ? null : $backup['type'] ),
			'description' => $backup['description'],
			'version' => $backup['version'],
		);
		
		if( array_key_exists( 'pattern', $backup ) ) $config['pattern'] = $backup['pattern'];
		if( array_key_exists( 'default', $backup ) ) $config['default'] = $backup['default'];
		if( array_key_exists( 'php', $backup ) ) $config['php'] = $backup['php'];
		
		if( array_key_exists( 'types', $backup ) && count( $backup['types'] ) > 0 ) {
			$config['types'] = array();
			foreach( $backup['types'] as $type )
				$config['types'][$type[0]] = count( $type[1] ) == 1 ? $type[1][0] : (count( $type[1] ) == 0 ? null : $type[1] );
		}
		
		if( array_key_exists( 'patterns', $backup ) && count( $backup['patterns'] ) > 0 ) {
			$config['patterns'] = array();
			foreach( $backup['patterns'] as $pattern )
				$config['patterns'][$pattern[0]] = $pattern[1];
		}
		
		if( array_key_exists( 'history', $backup ) && count( $backup['history'] ) > 0 ) {
			$config['history'] = array();
			foreach( $backup['history'] as $line )
				$config['history'][$line[0]] = $line[1];
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
	
	# json_encode doesn’t like (at all) malformed UTF-8. The constant JSON_PARTIAL_OUTPUT_ON_ERROR is needed to continue
	# in such cases. One malformed UTF-8 sequence was found (see above), but 3d08ab7 probably has malformed sequences (the \xC2\x90 is correct)
	# there are other cases before the 1500th commit.
	$json = json_encode( $globalSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	#$json = json_encode( $globalSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
	
	if( !$json ) echo json_last_error_msg();
	
	return $json;
}

